<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Admin;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Tests\Integration\IntegrationTestCase;

/**
 * HTTP integration coverage for every `/api/v1/admin/users*` and
 * `/api/v1/admin/security-questions` route in `RouteRegistrar`.
 *
 * Each test exercises the real Slim middleware stack (auth + policy + rate limit), asserts
 * a deterministic status code + response envelope, and checks one persistence or audit
 * side-effect where the endpoint mutates state.
 */
final class UserAdminRoutesTest extends IntegrationTestCase
{
    public function testAdminCanCreateAndListUsers(): void
    {
        $this->createUser('admin', 'administrator');
        $token = $this->login('admin');

        // POST /admin/users
        $create = $this->request('POST', '/api/v1/admin/users', [
            'username' => 'new_operator',
            'password' => 'StrongPass!12345',
            'display_name' => 'New Operator',
            'status' => 'active',
        ], $this->bearer($token));
        self::assertSame(201, $create->getStatusCode());
        $created = $this->decode($create)['data'];
        self::assertSame('new_operator', $created['username']);
        self::assertSame('active', $created['status']);
        self::assertArrayNotHasKey('password_hash', $created);

        // GET /admin/users — list includes the new record.
        $list = $this->request('GET', '/api/v1/admin/users', null, $this->bearer($token));
        self::assertSame(200, $list->getStatusCode());
        $usernames = array_column($this->decode($list)['data'], 'username');
        self::assertContains('new_operator', $usernames);

        // GET /admin/users/{id} — single record fetch.
        $id = (int) $created['id'];
        $single = $this->request('GET', '/api/v1/admin/users/' . $id, null, $this->bearer($token));
        self::assertSame(200, $single->getStatusCode());
        self::assertSame('new_operator', $this->decode($single)['data']['username']);
    }

    public function testNonAdminCannotListUsers(): void
    {
        $this->createUser('learner', 'learner');
        $token = $this->login('learner');
        $resp = $this->request('GET', '/api/v1/admin/users', null, $this->bearer($token));
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('NOT_AUTHORIZED', $this->decode($resp)['error']['code']);
    }

    public function testCreateUserRejectsInvalidUsernamePattern(): void
    {
        $this->createUser('admin', 'administrator');
        $token = $this->login('admin');
        $resp = $this->request('POST', '/api/v1/admin/users', [
            'username' => '!!!',
            'password' => 'StrongPass!12345',
        ], $this->bearer($token));
        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $this->decode($resp)['error']['code']);
    }

    public function testCreateUserRejectsDuplicateUsername(): void
    {
        $this->createUser('admin', 'administrator');
        $this->createUser('incumbent', 'learner');
        $token = $this->login('admin');
        $resp = $this->request('POST', '/api/v1/admin/users', [
            'username' => 'incumbent',
            'password' => 'StrongPass!12345',
        ], $this->bearer($token));
        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $this->decode($resp)['error']['code']);
    }

    public function testPatchUpdatesUserDisplayAndStatus(): void
    {
        $admin = $this->createUser('admin', 'administrator');
        $target = $this->createUser('somebody', 'learner');
        $token = $this->login('admin');

        $patch = $this->request('PATCH', '/api/v1/admin/users/' . $target->id, [
            'display_name' => 'Updated Name',
            'status' => 'disabled',
        ], $this->bearer($token));
        self::assertSame(200, $patch->getStatusCode());
        $data = $this->decode($patch)['data'];
        self::assertSame('Updated Name', $data['display_name']);
        self::assertSame('disabled', $data['status']);

        // Persistence check.
        $row = DB::table('users')->where('id', $target->id)->first();
        self::assertSame('disabled', $row->status);
        self::assertSame('Updated Name', $row->display_name);

        // Audit check.
        self::assertTrue(
            DB::table('audit_logs')
                ->where('action', 'auth.user_updated')
                ->where('object_id', (string) $target->id)
                ->exists(),
        );
    }

    public function testPatchRejectsIllegalStatusTransition(): void
    {
        $admin = $this->createUser('admin', 'administrator');
        $target = $this->createUser('disabledfoo', 'learner');
        DB::table('users')->where('id', $target->id)->update(['status' => 'disabled']);
        $token = $this->login('admin');

        $resp = $this->request('PATCH', '/api/v1/admin/users/' . $target->id, [
            'status' => 'locked',
        ], $this->bearer($token));
        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $this->decode($resp)['error']['code']);
    }

    public function testRoleBindingAssignAndRemove(): void
    {
        $this->createUser('admin', 'administrator');
        $target = $this->createUser('pupil', 'learner');
        $token = $this->login('admin');

        // Assign reviewer role.
        $assign = $this->request(
            'POST',
            '/api/v1/admin/users/' . $target->id . '/role-bindings',
            ['role' => 'reviewer'],
            $this->bearer($token),
        );
        self::assertSame(201, $assign->getStatusCode());
        $bindingId = (int) $this->decode($assign)['data']['id'];
        self::assertSame('reviewer', $this->decode($assign)['data']['role']);
        self::assertTrue(DB::table('user_role_bindings')->where('id', $bindingId)->exists());

        // Remove it.
        $del = $this->request(
            'DELETE',
            '/api/v1/admin/users/' . $target->id . '/role-bindings/' . $bindingId,
            null,
            $this->bearer($token),
        );
        self::assertSame(200, $del->getStatusCode());
        self::assertTrue((bool) $this->decode($del)['data']['removed']);
        self::assertFalse(DB::table('user_role_bindings')->where('id', $bindingId)->exists());
    }

    public function testAdminPasswordResetClearsExistingSessions(): void
    {
        $this->createUser('admin', 'administrator');
        $target = $this->createUser('victim', 'learner');
        $adminToken = $this->login('admin');
        $victimToken = $this->login('victim'); // active session for the target

        $resp = $this->request(
            'POST',
            '/api/v1/admin/users/' . $target->id . '/password-reset',
            ['new_password' => 'ReplacementPass!2026'],
            $this->bearer($adminToken),
        );
        self::assertSame(200, $resp->getStatusCode());
        self::assertTrue((bool) $this->decode($resp)['data']['reset']);

        // Target's previously-issued token is revoked.
        $me = $this->request('GET', '/api/v1/auth/me', null, $this->bearer($victimToken));
        self::assertSame(401, $me->getStatusCode());

        // Target status set to password_reset_required.
        $row = DB::table('users')->where('id', $target->id)->first();
        self::assertSame('password_reset_required', $row->status);
    }

    public function testSelfCanSetSecurityAnswers(): void
    {
        $user = $this->createUser('alex', 'learner');
        $token = $this->login('alex');
        $questionId = (int) DB::table('security_questions')->first()->id;

        $resp = $this->request(
            'POST',
            '/api/v1/admin/users/' . $user->id . '/security-answers',
            [
                'answers' => [
                    ['question_id' => $questionId, 'answer' => 'rex'],
                    ['question_id' => (int) DB::table('security_questions')->skip(1)->first()->id, 'answer' => 'springfield'],
                ],
            ],
            $this->bearer($token),
        );
        self::assertSame(201, $resp->getStatusCode());
        self::assertSame(2, $this->decode($resp)['data']['count']);
        self::assertSame(2, DB::table('user_security_answers')->where('user_id', $user->id)->count());
    }

    public function testUserCannotSetAnotherUsersSecurityAnswers(): void
    {
        $alice = $this->createUser('alice', 'learner');
        $bob = $this->createUser('bob', 'learner');
        $token = $this->login('alice');
        $questionId = (int) DB::table('security_questions')->first()->id;

        $resp = $this->request(
            'POST',
            '/api/v1/admin/users/' . $bob->id . '/security-answers',
            ['answers' => [['question_id' => $questionId, 'answer' => 'rex'], ['question_id' => $questionId, 'answer' => 'rex']]],
            $this->bearer($token),
        );
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testSecurityQuestionsAdminEndpointRequiresAuth(): void
    {
        $this->createUser('someone', 'learner');
        $token = $this->login('someone');
        $resp = $this->request('GET', '/api/v1/admin/security-questions', null, $this->bearer($token));
        self::assertSame(200, $resp->getStatusCode());
        $items = $this->decode($resp)['data'];
        self::assertNotEmpty($items);
        self::assertArrayHasKey('id', $items[0]);
        self::assertArrayHasKey('prompt', $items[0]);
    }
}
