<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Auth;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Tests\Integration\IntegrationTestCase;

final class SignupTest extends IntegrationTestCase
{
    private function questionIds(): array
    {
        return DB::table('security_questions')->pluck('id')->map(static fn($v) => (int) $v)->all();
    }

    public function testPublicSecurityQuestionsEndpointIsReachable(): void
    {
        $resp = $this->request('GET', '/api/v1/auth/security-questions');
        self::assertSame(200, $resp->getStatusCode());
        $items = $this->decode($resp)['data'];
        self::assertNotEmpty($items);
        foreach ($items as $q) {
            self::assertArrayHasKey('id', $q);
            self::assertArrayHasKey('prompt', $q);
        }
    }

    public function testSignupHappyPath(): void
    {
        $ids = $this->questionIds();
        $resp = $this->request('POST', '/api/v1/auth/signup', [
            'username' => 'newuser',
            'password' => 'ValidPassword123!',
            'display_name' => 'New User',
            'security_answers' => [
                ['question_id' => $ids[0], 'answer' => 'first answer'],
                ['question_id' => $ids[1], 'answer' => 'second answer'],
            ],
        ]);
        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getBody());
        $data = $this->decode($resp)['data'];
        self::assertNotEmpty($data['token']);
        self::assertSame('newuser', $data['user']['username']);
        self::assertSame('learner', $data['user']['role']);

        // Response must not leak sensitive fields.
        $serialized = json_encode($data);
        self::assertStringNotContainsString('password_hash', (string) $serialized);
        self::assertStringNotContainsString('answer_ciphertext', (string) $serialized);

        // Account exists and has learner role binding.
        $user = User::query()->where('username', 'newuser')->firstOrFail();
        self::assertSame('active', $user->status);
        self::assertTrue(UserPermissions::hasRole($user, 'learner'));
        self::assertSame(2, DB::table('user_security_answers')->where('user_id', $user->id)->count());

        // Audit row captured.
        self::assertTrue(
            DB::table('audit_logs')->where('action', 'auth.signup_completed')->where('object_id', (string) $user->id)->exists(),
        );
    }

    public function testSignupLoginRoundTrip(): void
    {
        $ids = $this->questionIds();
        $this->request('POST', '/api/v1/auth/signup', [
            'username' => 'rtuser',
            'password' => 'AnotherGood987!',
            'security_answers' => [
                ['question_id' => $ids[0], 'answer' => 'a'],
                ['question_id' => $ids[1], 'answer' => 'b'],
            ],
        ]);
        $login = $this->request('POST', '/api/v1/auth/login', [
            'username' => 'rtuser',
            'password' => 'AnotherGood987!',
        ]);
        self::assertSame(200, $login->getStatusCode());
    }

    public function testDuplicateUsernameRejected(): void
    {
        $ids = $this->questionIds();
        $payload = [
            'username' => 'dupuser',
            'password' => 'DuplicatedOne12!',
            'security_answers' => [
                ['question_id' => $ids[0], 'answer' => 'a'],
                ['question_id' => $ids[1], 'answer' => 'b'],
            ],
        ];
        $first = $this->request('POST', '/api/v1/auth/signup', $payload);
        self::assertSame(201, $first->getStatusCode());
        $second = $this->request('POST', '/api/v1/auth/signup', $payload);
        self::assertSame(409, $second->getStatusCode());
        self::assertSame('USERNAME_TAKEN', $this->decode($second)['error']['code']);
    }

    public function testShortPasswordRejected(): void
    {
        $ids = $this->questionIds();
        $resp = $this->request('POST', '/api/v1/auth/signup', [
            'username' => 'weakpass',
            'password' => 'short',
            'security_answers' => [
                ['question_id' => $ids[0], 'answer' => 'a'],
                ['question_id' => $ids[1], 'answer' => 'b'],
            ],
        ]);
        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $this->decode($resp)['error']['code']);
    }

    public function testInvalidUsernameRejected(): void
    {
        $ids = $this->questionIds();
        $resp = $this->request('POST', '/api/v1/auth/signup', [
            'username' => '1', // too short, invalid pattern
            'password' => 'ValidPassword123!',
            'security_answers' => [
                ['question_id' => $ids[0], 'answer' => 'a'],
                ['question_id' => $ids[1], 'answer' => 'b'],
            ],
        ]);
        self::assertSame(422, $resp->getStatusCode());
    }

    public function testInsufficientSecurityAnswersRejected(): void
    {
        $ids = $this->questionIds();
        $resp = $this->request('POST', '/api/v1/auth/signup', [
            'username' => 'onlyone',
            'password' => 'ValidPassword123!',
            'security_answers' => [
                ['question_id' => $ids[0], 'answer' => 'a'],
            ],
        ]);
        self::assertSame(422, $resp->getStatusCode());
    }

    public function testUnknownSecurityQuestionIdRejected(): void
    {
        $resp = $this->request('POST', '/api/v1/auth/signup', [
            'username' => 'badqid',
            'password' => 'ValidPassword123!',
            'security_answers' => [
                ['question_id' => 9999, 'answer' => 'a'],
                ['question_id' => 10000, 'answer' => 'b'],
            ],
        ]);
        self::assertSame(422, $resp->getStatusCode());
    }
}
