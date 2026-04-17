<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Events;

use Meridian\Tests\Integration\IntegrationTestCase;

final class EventLifecycleTest extends IntegrationTestCase
{
    public function testInstructorCanCreateButNotPublish(): void
    {
        $this->createUser('instr', 'instructor');
        $token = $this->login('instr');
        $create = $this->request('POST', '/api/v1/events', [
            'name' => 'Spring Classic',
            'template_key' => 'individual_standard',
            'event_family_key' => 'classics',
        ], $this->bearer($token));
        self::assertSame(201, $create->getStatusCode());
        $data = $this->decode($create)['data'];
        $eventId = $data['event_id'];
        $versionId = $data['initial_version_id'];
        self::assertIsString($eventId);
        self::assertNotSame('', $eventId);
        self::assertIsInt($versionId);
        self::assertGreaterThan(0, $versionId);

        // Instructor publish attempt -> 403 with deterministic error code.
        $pub = $this->request('POST', "/api/v1/events/{$eventId}/versions/{$versionId}/publish", null, $this->bearer($token));
        self::assertSame(403, $pub->getStatusCode());
        self::assertSame('NOT_AUTHORIZED', $this->decode($pub)['error']['code']);

        // Administrator publishes successfully and the response surfaces the new state.
        $this->createUser('admin', 'administrator');
        $adminToken = $this->login('admin');
        $ok = $this->request('POST', "/api/v1/events/{$eventId}/versions/{$versionId}/publish", null, $this->bearer($adminToken));
        self::assertSame(200, $ok->getStatusCode());
        $okData = $this->decode($ok)['data'];
        self::assertSame($versionId, (int) $okData['id']);
        self::assertSame('published', $okData['status']);
        self::assertNotEmpty($okData['published_at']);
    }

    public function testUnauthenticatedCreateRejected(): void
    {
        $resp = $this->request('POST', '/api/v1/events', [
            'name' => 'x', 'template_key' => 'individual_standard', 'event_family_key' => 'classics',
        ]);
        self::assertSame(401, $resp->getStatusCode());
        self::assertSame('AUTHENTICATION_REQUIRED', $this->decode($resp)['error']['code']);
    }
}
