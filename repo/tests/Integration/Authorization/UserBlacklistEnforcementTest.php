<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Authorization;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Tests\Integration\IntegrationTestCase;

/**
 * Round-3 Fix E coverage.
 *
 * After a `user` blacklist entry is created, the centralized AuthMiddleware gate must
 * deny every protected request from that user with 403 USER_BLACKLISTED, regardless of
 * whether a valid session token was previously issued. Non-blacklisted users continue
 * to flow through normally.
 */
final class UserBlacklistEnforcementTest extends IntegrationTestCase
{
    private function blacklistUser(int $userId, string $creatorUsername = 'admin'): void
    {
        $this->createUser($creatorUsername, 'administrator');
        $adminToken = $this->login($creatorUsername);
        $resp = $this->request('POST', '/api/v1/blacklists', [
            'entry_type' => 'user',
            'target_key' => (string) $userId,
            'reason' => 'integration test',
        ], $this->bearer($adminToken));
        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getBody());
    }

    public function testBlacklistedUserIsDeniedOnProtectedRoute(): void
    {
        $user = $this->createUser('victim', 'learner');
        $token = $this->login('victim');

        // Confirm the token works before blacklisting.
        $me = $this->request('GET', '/api/v1/auth/me', null, $this->bearer($token));
        self::assertSame(200, $me->getStatusCode());

        $this->blacklistUser((int) $user->id);

        $denied = $this->request('GET', '/api/v1/auth/me', null, $this->bearer($token));
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('USER_BLACKLISTED', $this->decode($denied)['error']['code']);

        // Audit row emitted by the middleware.
        self::assertTrue(
            DB::table('audit_logs')
                ->where('action', 'auth.blacklist_denied')
                ->where('object_id', (string) $user->id)
                ->exists(),
            'middleware must emit auth.blacklist_denied audit entry',
        );
    }

    public function testBlacklistedUserCannotSubmitModerationReport(): void
    {
        $user = $this->createUser('banreporter', 'learner');
        $author = $this->createUser('banauthor', 'instructor');
        $authorToken = $this->login('banauthor');
        $resp = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text',
            'title' => 'for report',
            'payload' => $this->englishBody(),
            'media_source' => 'article',
        ], $this->bearer($authorToken));
        $contentId = $this->decode($resp)['data']['content']['content_id'];

        $token = $this->login('banreporter');
        $this->blacklistUser((int) $user->id);
        $report = $this->request('POST', '/api/v1/moderation/reports', [
            'content_id' => $contentId,
            'reason_code' => 'spam',
        ], $this->bearer($token));
        self::assertSame(403, $report->getStatusCode());
        self::assertSame('USER_BLACKLISTED', $this->decode($report)['error']['code']);
        self::assertSame(0, DB::table('moderation_reports')->count());
    }

    public function testBlacklistedUserCannotIngestAnalytics(): void
    {
        $user = $this->createUser('banalytics', 'reviewer');
        $token = $this->login('banalytics');
        $this->blacklistUser((int) $user->id);

        $resp = $this->request('POST', '/api/v1/analytics/events', [
            'event_type' => 'content_view',
            'object_type' => 'content',
            'object_id' => 'x',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'idempotency_key' => 'bl-k-1',
        ], $this->bearer($token));
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('USER_BLACKLISTED', $this->decode($resp)['error']['code']);
        self::assertSame(0, DB::table('analytics_events')->count());
    }

    public function testNonBlacklistedUserUnaffected(): void
    {
        // Exercise a blacklist entry for a different user and confirm our actor still flows.
        $victim = $this->createUser('victim', 'learner');
        $this->blacklistUser((int) $victim->id);

        $this->createUser('normal', 'reviewer');
        $token = $this->login('normal');
        $resp = $this->request('GET', '/api/v1/auth/me', null, $this->bearer($token));
        self::assertSame(200, $resp->getStatusCode());
    }

    public function testRevokingBlacklistRestoresAccess(): void
    {
        $user = $this->createUser('recover', 'learner');
        $token = $this->login('recover');
        $this->blacklistUser((int) $user->id);

        $denied = $this->request('GET', '/api/v1/auth/me', null, $this->bearer($token));
        self::assertSame(403, $denied->getStatusCode());

        // Revoke via admin.
        $entryId = (int) DB::table('blacklists')->where('entry_type', 'user')->where('target_key', (string) $user->id)->value('id');
        $adminToken = $this->login('admin');
        $revoke = $this->request('DELETE', '/api/v1/blacklists/' . $entryId, null, $this->bearer($adminToken));
        self::assertSame(200, $revoke->getStatusCode());

        $restored = $this->request('GET', '/api/v1/auth/me', null, $this->bearer($token));
        self::assertSame(200, $restored->getStatusCode());
    }
}
