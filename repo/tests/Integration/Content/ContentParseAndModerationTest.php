<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Content;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Tests\Integration\IntegrationTestCase;

final class ContentParseAndModerationTest extends IntegrationTestCase
{
    private function seedRulePack(string $bannedDomain = 'bad.example'): int
    {
        $now = date('Y-m-d H:i:s');
        DB::table('rule_packs')->insert([
            'key' => 'default', 'description' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $packId = (int) DB::table('rule_packs')->where('key', 'default')->value('id');
        DB::table('rule_pack_versions')->insert([
            'rule_pack_id' => $packId, 'version' => 1, 'status' => 'published',
            'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = (int) DB::table('rule_pack_versions')->where('rule_pack_id', $packId)->value('id');
        DB::table('rule_pack_rules')->insert([
            ['rule_pack_version_id' => $versionId, 'rule_kind' => 'keyword', 'pattern' => 'prohibited-term',
             'threshold' => null, 'severity' => 'warning', 'description' => null, 'created_at' => $now],
            ['rule_pack_version_id' => $versionId, 'rule_kind' => 'banned_domain', 'pattern' => $bannedDomain,
             'threshold' => null, 'severity' => 'critical', 'description' => null, 'created_at' => $now],
            ['rule_pack_version_id' => $versionId, 'rule_kind' => 'ad_link_density', 'pattern' => null,
             'threshold' => 3.0, 'severity' => 'warning', 'description' => null, 'created_at' => $now],
        ]);
        return $versionId;
    }

    public function testParseReturnsFullNormalizedObject(): void
    {
        $this->createUser('parser', 'instructor');
        $token = $this->login('parser');
        $longBody = $this->englishBody();
        $response = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text',
            'title' => 'Test Title',
            'payload' => $longBody,
            'media_source' => 'article',
            'author' => 'Jane Example',
            'section_tags' => ['News', 'Science'],
        ], $this->bearer($token));
        self::assertSame(201, $response->getStatusCode());
        $content = $this->decode($response)['data']['content'];
        foreach (['content_id', 'title', 'body', 'language', 'author', 'published_at', 'media_source', 'section_tags'] as $field) {
            self::assertArrayHasKey($field, $content, "missing required field {$field}");
        }
        self::assertSame('Test Title', $content['title']);
        self::assertSame('Jane Example', $content['author']);
        self::assertSame('article', $content['media_source']);
        self::assertIsArray($content['section_tags']);
    }

    public function testShortBodyIsRejected(): void
    {
        $this->createUser('parser', 'instructor');
        $token = $this->login('parser');
        $response = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text',
            'title' => 'Short',
            'payload' => 'too short',
            'media_source' => 'article',
        ], $this->bearer($token));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $this->decode($response)['error']['code']);
    }

    public function testAutomatedModerationCreatesCaseOnKeywordHit(): void
    {
        $this->seedRulePack();
        $this->createUser('parser', 'instructor');
        $token = $this->login('parser');
        // English body padded around the keyword so LanguageDetector clears 0.75 confidence
        // AND the keyword rule in the seeded pack still matches.
        $longBody = $this->englishBody(5) . ' This text contains a prohibited-term deliberately. ' . $this->englishBody(5);
        $response = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text',
            'title' => 'Policy violation article',
            'payload' => $longBody,
            'media_source' => 'article',
        ], $this->bearer($token));
        self::assertSame(201, $response->getStatusCode());
        $body = $this->decode($response)['data'];
        self::assertNotNull($body['automated_moderation']);
        self::assertSame(1, $body['automated_moderation']['flag_count']);
        self::assertSame('flagged', $body['content']['risk_state']);

        $cases = DB::table('moderation_cases')->where('content_id', $body['content']['content_id'])->get();
        self::assertCount(1, $cases);
        self::assertSame('automated_flag', $cases[0]->case_type);
    }

    public function testCleanInputProducesNoCase(): void
    {
        $this->seedRulePack();
        $this->createUser('parser', 'instructor');
        $token = $this->login('parser');
        $longBody = $this->englishBody();
        $response = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text',
            'title' => 'Garden story',
            'payload' => $longBody,
            'media_source' => 'article',
        ], $this->bearer($token));
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(0, $this->decode($response)['data']['automated_moderation']['flag_count']);
        self::assertSame('normalized', $this->decode($response)['data']['content']['risk_state']);
        self::assertSame(0, DB::table('moderation_cases')->count());
    }

    public function testAutomatedCaseIsCreatedOnBannedDomainHit(): void
    {
        $this->seedRulePack();
        $this->createUser('parser', 'instructor');
        $token = $this->login('parser');
        $html = '<html><body><article><h1>With bad link</h1><p>' .
            $this->englishBody() .
            '<a href="https://bad.example/article">link</a></p></article></body></html>';
        $response = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'html',
            'payload' => $html,
            'media_source' => 'article',
        ], $this->bearer($token));
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('quarantined', $this->decode($response)['data']['content']['risk_state']);
        self::assertSame(1, DB::table('moderation_cases')->count());
    }
}
