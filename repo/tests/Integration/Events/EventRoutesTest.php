<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Events;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Tests\Integration\IntegrationTestCase;

/**
 * Fills the event routes not already exercised by EventLifecycleTest.
 *
 *   GET   /api/v1/events
 *   GET   /api/v1/events/{id}
 *   POST  /api/v1/events/{id}/versions
 *   PATCH /api/v1/events/{id}/versions/{versionId}
 *   POST  /api/v1/events/{id}/versions/{versionId}/rollback
 *   POST  /api/v1/events/{id}/versions/{versionId}/cancel
 *   GET   /api/v1/events/{id}/versions/{versionId}
 *   POST  /api/v1/events/{id}/versions/{versionId}/bindings
 */
final class EventRoutesTest extends IntegrationTestCase
{
    private function seedVenue(string $key = 'main_hall'): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) DB::table('event_venues')->insertGetId([
            'key' => $key,
            'name' => 'Main Hall',
            'location_description' => 'Primary indoor venue',
            'capacity' => 200,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array{event_id:string,initial_version_id:int,admin_token:string} */
    private function createEventAsAdmin(): array
    {
        $this->createUser('admin', 'administrator');
        $token = $this->login('admin');
        $create = $this->request('POST', '/api/v1/events', [
            'name' => 'Spring Classic',
            'template_key' => 'individual_standard',
            'event_family_key' => 'classics',
        ], $this->bearer($token));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $data = $this->decode($create)['data'];
        return [
            'event_id' => (string) $data['event_id'],
            'initial_version_id' => (int) $data['initial_version_id'],
            'admin_token' => $token,
        ];
    }

    public function testListAndGetEvents(): void
    {
        $ctx = $this->createEventAsAdmin();

        $list = $this->request('GET', '/api/v1/events', null, $this->bearer($ctx['admin_token']));
        self::assertSame(200, $list->getStatusCode());
        $items = $this->decode($list)['data'];
        $ids = array_column($items, 'event_id');
        self::assertContains($ctx['event_id'], $ids);

        $single = $this->request('GET', '/api/v1/events/' . $ctx['event_id'], null, $this->bearer($ctx['admin_token']));
        self::assertSame(200, $single->getStatusCode());
        $data = $this->decode($single)['data'];
        self::assertSame('Spring Classic', $data['name']);
        self::assertArrayHasKey('versions', $data);
        self::assertNotEmpty($data['versions']);
    }

    public function testCreateAndUpdateDraftVersion(): void
    {
        $ctx = $this->createEventAsAdmin();

        // POST /events/{id}/versions — second draft atop the initial one.
        $draftResp = $this->request('POST', '/api/v1/events/' . $ctx['event_id'] . '/versions', [
            'base_config' => ['name' => 'Spring Classic v2'],
        ], $this->bearer($ctx['admin_token']));
        self::assertSame(201, $draftResp->getStatusCode());
        $newVersionId = (int) $this->decode($draftResp)['data']['id'];
        self::assertSame('draft', $this->decode($draftResp)['data']['status']);

        // PATCH /events/{id}/versions/{versionId}
        $patch = $this->request(
            'PATCH',
            '/api/v1/events/' . $ctx['event_id'] . '/versions/' . $newVersionId,
            [
                'name' => 'Updated name',
                'expected_draft_version_number' => 1,
                'rule_set' => ['attempt_limit' => 5, 'checkin_open_minutes_before' => 45],
                'advancement_rules' => [
                    ['precedence' => 1, 'criterion' => 'explicit_rank'],
                    ['precedence' => 2, 'criterion' => 'score_metric'],
                ],
            ],
            $this->bearer($ctx['admin_token']),
        );
        self::assertSame(200, $patch->getStatusCode());
        self::assertSame(2, (int) $this->decode($patch)['data']['draft_version_number']);

        // GET /events/{id}/versions/{versionId} — reflects the update.
        $getVersion = $this->request(
            'GET',
            '/api/v1/events/' . $ctx['event_id'] . '/versions/' . $newVersionId,
            null,
            $this->bearer($ctx['admin_token']),
        );
        self::assertSame(200, $getVersion->getStatusCode());
        $versionData = $this->decode($getVersion)['data'];
        self::assertSame(5, (int) $versionData['rule_set']['attempt_limit']);
        self::assertCount(2, $versionData['advancement_rules']);
    }

    public function testPatchDraftRejectsStaleOptimisticLock(): void
    {
        $ctx = $this->createEventAsAdmin();
        $resp = $this->request(
            'PATCH',
            '/api/v1/events/' . $ctx['event_id'] . '/versions/' . $ctx['initial_version_id'],
            [
                'name' => 'conflict',
                'expected_draft_version_number' => 99, // wrong on purpose
            ],
            $this->bearer($ctx['admin_token']),
        );
        self::assertSame(409, $resp->getStatusCode());
        self::assertSame('DRAFT_LOCK_CONFLICT', $this->decode($resp)['error']['code']);
    }

    public function testBindingAddedToDraftVersion(): void
    {
        $ctx = $this->createEventAsAdmin();
        $venueId = $this->seedVenue();

        $resp = $this->request(
            'POST',
            '/api/v1/events/' . $ctx['event_id'] . '/versions/' . $ctx['initial_version_id'] . '/bindings',
            [
                'binding_type' => 'venue',
                'venue_id' => $venueId,
                'quantity' => 1,
                'notes' => 'primary stage',
            ],
            $this->bearer($ctx['admin_token']),
        );
        self::assertSame(201, $resp->getStatusCode());
        $data = $this->decode($resp)['data'];
        self::assertSame('venue', $data['binding_type']);
        self::assertSame($venueId, (int) $data['venue_id']);
        self::assertSame(1, DB::table('event_bindings')
            ->where('event_version_id', $ctx['initial_version_id'])
            ->where('venue_id', $venueId)
            ->count());
    }

    public function testRollbackActivatesPriorVersion(): void
    {
        $ctx = $this->createEventAsAdmin();

        // Publish v1 then draft+publish v2.
        $this->request('POST', "/api/v1/events/{$ctx['event_id']}/versions/{$ctx['initial_version_id']}/publish", null, $this->bearer($ctx['admin_token']));
        $v2Resp = $this->request('POST', "/api/v1/events/{$ctx['event_id']}/versions", null, $this->bearer($ctx['admin_token']));
        $v2Id = (int) $this->decode($v2Resp)['data']['id'];
        $this->request('POST', "/api/v1/events/{$ctx['event_id']}/versions/{$v2Id}/publish", null, $this->bearer($ctx['admin_token']));

        // Roll back to v1.
        $rollback = $this->request(
            'POST',
            "/api/v1/events/{$ctx['event_id']}/versions/{$ctx['initial_version_id']}/rollback",
            ['rationale' => 'regression in v2'],
            $this->bearer($ctx['admin_token']),
        );
        self::assertSame(200, $rollback->getStatusCode());
        self::assertSame('published', $this->decode($rollback)['data']['status']);

        // Persistence: event.active_version_id should now point at v1 again.
        $event = DB::table('events')->where('event_id', $ctx['event_id'])->first();
        self::assertSame($ctx['initial_version_id'], (int) $event->active_version_id);
        // v2 marked rolled_back.
        self::assertSame('rolled_back', DB::table('event_versions')->where('id', $v2Id)->value('status'));
    }

    public function testCancelPublishedVersion(): void
    {
        $ctx = $this->createEventAsAdmin();
        $this->request('POST', "/api/v1/events/{$ctx['event_id']}/versions/{$ctx['initial_version_id']}/publish", null, $this->bearer($ctx['admin_token']));

        $resp = $this->request(
            'POST',
            "/api/v1/events/{$ctx['event_id']}/versions/{$ctx['initial_version_id']}/cancel",
            ['rationale' => 'event postponed'],
            $this->bearer($ctx['admin_token']),
        );
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('cancelled', $this->decode($resp)['data']['status']);

        $event = DB::table('events')->where('event_id', $ctx['event_id'])->first();
        self::assertNull($event->active_version_id);
    }

    public function testListEventsRequiresAuth(): void
    {
        $resp = $this->request('GET', '/api/v1/events');
        self::assertSame(401, $resp->getStatusCode());
    }
}
