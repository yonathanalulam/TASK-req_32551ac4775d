<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Moderation;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Tests\Integration\IntegrationTestCase;

/**
 * HTTP coverage for every `/api/v1/rule-packs*` route.
 *
 *   GET    /rule-packs
 *   POST   /rule-packs
 *   POST   /rule-packs/{id}/versions
 *   POST   /rule-packs/versions/{versionId}/rules
 *   POST   /rule-packs/versions/{versionId}/publish
 *   POST   /rule-packs/versions/{versionId}/archive
 *   GET    /rule-packs/versions/{versionId}
 *
 * The happy path walks the full lifecycle (create pack -> draft version -> add rule ->
 * publish -> archive) and asserts persistence state at each step.
 */
final class RulePackRoutesTest extends IntegrationTestCase
{
    public function testFullRulePackLifecycle(): void
    {
        $this->createUser('admin', 'administrator');
        $token = $this->login('admin');

        // POST /rule-packs
        $create = $this->request('POST', '/api/v1/rule-packs', [
            'key' => 'integration_pack',
            'description' => 'Integration test pack',
        ], $this->bearer($token));
        self::assertSame(201, $create->getStatusCode());
        $packId = (int) $this->decode($create)['data']['id'];
        self::assertSame('integration_pack', $this->decode($create)['data']['key']);

        // POST /rule-packs/{id}/versions
        $draft = $this->request('POST', "/api/v1/rule-packs/{$packId}/versions", [
            'notes' => 'v1 draft',
        ], $this->bearer($token));
        self::assertSame(201, $draft->getStatusCode());
        $versionId = (int) $this->decode($draft)['data']['id'];
        self::assertSame('draft', $this->decode($draft)['data']['status']);

        // POST /rule-packs/versions/{versionId}/rules
        $rule = $this->request('POST', "/api/v1/rule-packs/versions/{$versionId}/rules", [
            'rule_kind' => 'keyword',
            'pattern' => 'prohibited-term',
            'severity' => 'warning',
            'description' => 'flag when present',
        ], $this->bearer($token));
        self::assertSame(201, $rule->getStatusCode());
        self::assertSame('keyword', $this->decode($rule)['data']['rule_kind']);

        // GET /rule-packs/versions/{versionId}
        $getVersion = $this->request('GET', "/api/v1/rule-packs/versions/{$versionId}", null, $this->bearer($token));
        self::assertSame(200, $getVersion->getStatusCode());
        $versionData = $this->decode($getVersion)['data'];
        self::assertSame('draft', $versionData['status']);
        self::assertCount(1, $versionData['rules']);
        self::assertSame('keyword', $versionData['rules'][0]['rule_kind']);

        // POST /rule-packs/versions/{versionId}/publish
        $publish = $this->request('POST', "/api/v1/rule-packs/versions/{$versionId}/publish", null, $this->bearer($token));
        self::assertSame(200, $publish->getStatusCode());
        self::assertSame('published', $this->decode($publish)['data']['status']);
        self::assertSame('published', DB::table('rule_pack_versions')->where('id', $versionId)->value('status'));

        // GET /rule-packs
        $list = $this->request('GET', '/api/v1/rule-packs', null, $this->bearer($token));
        self::assertSame(200, $list->getStatusCode());
        $keys = array_column($this->decode($list)['data'], 'key');
        self::assertContains('integration_pack', $keys);

        // POST /rule-packs/versions/{versionId}/archive
        $archive = $this->request('POST', "/api/v1/rule-packs/versions/{$versionId}/archive", null, $this->bearer($token));
        self::assertSame(200, $archive->getStatusCode());
        self::assertSame('archived', $this->decode($archive)['data']['status']);
        self::assertSame('archived', DB::table('rule_pack_versions')->where('id', $versionId)->value('status'));
    }

    public function testCreatePackDeniedForNonAdmin(): void
    {
        $this->createUser('reviewer', 'reviewer');
        $token = $this->login('reviewer');
        $resp = $this->request('POST', '/api/v1/rule-packs', [
            'key' => 'unauthorized_pack',
        ], $this->bearer($token));
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('NOT_AUTHORIZED', $this->decode($resp)['error']['code']);
    }

    public function testPublishEmptyDraftReturnsValidationError(): void
    {
        $this->createUser('admin', 'administrator');
        $token = $this->login('admin');
        $pack = $this->request('POST', '/api/v1/rule-packs', ['key' => 'empty_pack'], $this->bearer($token));
        $packId = (int) $this->decode($pack)['data']['id'];
        $draft = $this->request('POST', "/api/v1/rule-packs/{$packId}/versions", null, $this->bearer($token));
        $versionId = (int) $this->decode($draft)['data']['id'];

        $resp = $this->request('POST', "/api/v1/rule-packs/versions/{$versionId}/publish", null, $this->bearer($token));
        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $this->decode($resp)['error']['code']);
    }

    public function testAddRuleRejectedAfterPublish(): void
    {
        $this->createUser('admin', 'administrator');
        $token = $this->login('admin');
        $pack = $this->request('POST', '/api/v1/rule-packs', ['key' => 'sealed_pack'], $this->bearer($token));
        $packId = (int) $this->decode($pack)['data']['id'];
        $draft = $this->request('POST', "/api/v1/rule-packs/{$packId}/versions", null, $this->bearer($token));
        $versionId = (int) $this->decode($draft)['data']['id'];
        $this->request('POST', "/api/v1/rule-packs/versions/{$versionId}/rules", [
            'rule_kind' => 'keyword', 'pattern' => 'xyz',
        ], $this->bearer($token));
        $this->request('POST', "/api/v1/rule-packs/versions/{$versionId}/publish", null, $this->bearer($token));

        // After publish the version is immutable.
        $resp = $this->request('POST', "/api/v1/rule-packs/versions/{$versionId}/rules", [
            'rule_kind' => 'keyword', 'pattern' => 'zzz',
        ], $this->bearer($token));
        self::assertSame(409, $resp->getStatusCode());
        self::assertSame('VERSION_NOT_DRAFT', $this->decode($resp)['error']['code']);
    }
}
