<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Auth;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Domain\Auth\PasswordResetTicket;
use Meridian\Domain\Auth\SecurityQuestion;
use Meridian\Domain\Auth\UserSecurityAnswer;
use Meridian\Infrastructure\Crypto\Cipher;
use Meridian\Tests\Integration\IntegrationTestCase;

final class AuthFlowTest extends IntegrationTestCase
{
    public function testLoginSucceedsForValidCredentials(): void
    {
        $this->createUser('alice', 'learner');
        $response = $this->request('POST', '/api/v1/auth/login', [
            'username' => 'alice',
            'password' => 'Pass1234!LongEnough',
        ]);
        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response)['data'];
        self::assertNotEmpty($data['token']);
        self::assertSame('alice', $data['user']['username']);
    }

    public function testLoginFailsWithBadPassword(): void
    {
        $this->createUser('bob', 'learner');
        $response = $this->request('POST', '/api/v1/auth/login', [
            'username' => 'bob',
            'password' => 'wrong-password',
        ]);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('AUTHENTICATION_REQUIRED', $this->decode($response)['error']['code']);
    }

    public function testProtectedRouteReturns401WithoutToken(): void
    {
        $response = $this->request('GET', '/api/v1/content');
        self::assertSame(401, $response->getStatusCode());
    }

    public function testLogoutRevokesSession(): void
    {
        $this->createUser('eve', 'learner');
        $token = $this->login('eve');
        $me1 = $this->request('GET', '/api/v1/auth/me', null, $this->bearer($token));
        self::assertSame(200, $me1->getStatusCode());

        $logout = $this->request('POST', '/api/v1/auth/logout', null, $this->bearer($token));
        self::assertSame(200, $logout->getStatusCode());

        $me2 = $this->request('GET', '/api/v1/auth/me', null, $this->bearer($token));
        self::assertSame(401, $me2->getStatusCode());
    }

    public function testPasswordResetRequiresValidTicket(): void
    {
        $user = $this->createUser('carol', 'learner');
        $cipher = $this->container->get(Cipher::class);
        $questions = SecurityQuestion::query()->get()->all();
        foreach ($questions as $q) {
            UserSecurityAnswer::query()->create([
                'user_id' => $user->id,
                'security_question_id' => $q->id,
                'answer_ciphertext' => $cipher->encrypt($q->prompt === 'First pet' ? 'rex' : 'springfield'),
                'key_version' => 1,
            ]);
        }

        $begin = $this->request('POST', '/api/v1/auth/password-reset/begin', ['username' => 'carol']);
        self::assertSame(200, $begin->getStatusCode());
        $beginData = $this->decode($begin)['data'];
        self::assertNotEmpty($beginData['reset_ticket']);
        self::assertCount(2, $beginData['questions']);

        // Without ticket: rejected.
        $noTicket = $this->request('POST', '/api/v1/auth/password-reset/complete', [
            'username' => 'carol',
            'new_password' => 'BrandNew12345!',
            'answers' => [
                ['question_id' => $beginData['questions'][0]['id'], 'answer' => $beginData['questions'][0]['prompt'] === 'First pet' ? 'rex' : 'springfield'],
                ['question_id' => $beginData['questions'][1]['id'], 'answer' => $beginData['questions'][1]['prompt'] === 'First pet' ? 'rex' : 'springfield'],
            ],
        ]);
        self::assertSame(422, $noTicket->getStatusCode());

        // Tampered ticket: rejected.
        $bad = $this->request('POST', '/api/v1/auth/password-reset/complete', [
            'username' => 'carol',
            'reset_ticket' => 'notarealticket.xxxx',
            'new_password' => 'BrandNew12345!',
            'answers' => [
                ['question_id' => $beginData['questions'][0]['id'], 'answer' => $beginData['questions'][0]['prompt'] === 'First pet' ? 'rex' : 'springfield'],
                ['question_id' => $beginData['questions'][1]['id'], 'answer' => $beginData['questions'][1]['prompt'] === 'First pet' ? 'rex' : 'springfield'],
            ],
        ]);
        self::assertSame(401, $bad->getStatusCode());

        // Valid ticket: succeeds.
        $answers = [];
        foreach ($beginData['questions'] as $q) {
            $answers[] = ['question_id' => $q['id'], 'answer' => $q['prompt'] === 'First pet' ? 'rex' : 'springfield'];
        }
        $good = $this->request('POST', '/api/v1/auth/password-reset/complete', [
            'username' => 'carol',
            'reset_ticket' => $beginData['reset_ticket'],
            'new_password' => 'BrandNew12345!',
            'answers' => $answers,
        ]);
        self::assertSame(200, $good->getStatusCode());

        // Replay: rejected.
        $replay = $this->request('POST', '/api/v1/auth/password-reset/complete', [
            'username' => 'carol',
            'reset_ticket' => $beginData['reset_ticket'],
            'new_password' => 'AnotherNewOne4567!',
            'answers' => $answers,
        ]);
        self::assertSame(401, $replay->getStatusCode());
        self::assertStringContainsString('already used', (string) $replay->getBody());

        // Ticket row persisted and consumed:
        $ticketRow = PasswordResetTicket::query()->where('user_id', $user->id)->first();
        self::assertNotNull($ticketRow);
        self::assertNotNull($ticketRow->consumed_at);
    }
}
