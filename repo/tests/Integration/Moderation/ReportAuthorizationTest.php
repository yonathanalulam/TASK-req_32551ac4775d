<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Moderation;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Tests\Integration\IntegrationTestCase;

final class ReportAuthorizationTest extends IntegrationTestCase
{
    private function publishContent(string $username = 'author'): string
    {
        $this->createUser($username, 'instructor');
        $token = $this->login($username);
        $resp = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text',
            'title' => 'Content for reports',
            'payload' => $this->englishBody(),
            'media_source' => 'article',
        ], $this->bearer($token));
        return $this->decode($resp)['data']['content']['content_id'];
    }

    public function testUnauthenticatedReportReturns401(): void
    {
        $resp = $this->request('POST', '/api/v1/moderation/reports', [
            'content_id' => 'nonexistent',
            'reason_code' => 'spam',
        ]);
        self::assertSame(401, $resp->getStatusCode());
    }

    public function testAuthenticatedWithoutPermissionReturns403(): void
    {
        // Strip the moderation.report.create permission from the learner role so we can
        // exercise an authenticated-but-unauthorized flow without inventing a new role.
        DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('roles.key', 'learner')
            ->where('permissions.key', 'moderation.report.create')
            ->delete();

        $contentId = $this->publishContent();
        $this->createUser('capless', 'learner');
        $token = $this->login('capless');
        $resp = $this->request('POST', '/api/v1/moderation/reports', [
            'content_id' => $contentId,
            'reason_code' => 'spam',
        ], $this->bearer($token));
        self::assertSame(403, $resp->getStatusCode());
        self::assertTrue(DB::table('audit_logs')->where('action', 'moderation.report_denied')->exists());
    }

    public function testAuthorizedReportSucceeds(): void
    {
        $contentId = $this->publishContent();
        $this->createUser('reporter', 'learner');
        $token = $this->login('reporter');
        $resp = $this->request('POST', '/api/v1/moderation/reports', [
            'content_id' => $contentId,
            'reason_code' => 'spam',
            'details' => 'Looks like spam.',
        ], $this->bearer($token));
        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getBody());
        self::assertSame(1, DB::table('moderation_reports')->count());
        self::assertTrue(DB::table('audit_logs')->where('action', 'moderation.report_submitted')->exists());
    }

    public function testOutOfScopeReportIsDenied(): void
    {
        // Produce content then restrict it so learners can no longer view it.
        $contentId = $this->publishContent();
        DB::table('contents')->where('content_id', $contentId)->update(['risk_state' => 'restricted']);

        $this->createUser('outer', 'learner');
        $token = $this->login('outer');
        $resp = $this->request('POST', '/api/v1/moderation/reports', [
            'content_id' => $contentId,
            'reason_code' => 'spam',
        ], $this->bearer($token));
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame(0, DB::table('moderation_reports')->count());
    }

    public function testReportAgainstUnknownContentReturnsValidationError(): void
    {
        $this->createUser('reporter', 'learner');
        $token = $this->login('reporter');
        $resp = $this->request('POST', '/api/v1/moderation/reports', [
            'content_id' => 'not-a-real-uuid',
            'reason_code' => 'spam',
        ], $this->bearer($token));
        self::assertSame(422, $resp->getStatusCode());
    }
}
