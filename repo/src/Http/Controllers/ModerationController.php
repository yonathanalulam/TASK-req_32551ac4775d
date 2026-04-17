<?php

declare(strict_types=1);

namespace Meridian\Http\Controllers;

use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Moderation\ModerationService;
use Meridian\Http\Responses\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ModerationController
{
    public function __construct(private readonly ModerationService $service)
    {
    }

    public function list(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $q = $req->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $size = min(100, max(1, (int) ($q['page_size'] ?? 25)));
        $out = $this->service->list($actor, $q, $page, $size);
        return ApiResponse::success($res, $out['items'], 200, ['page' => $page, 'page_size' => $size, 'total' => $out['total']]);
    }

    public function get(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $out = $this->service->get($actor, (string) $args['id']);
        return ApiResponse::success($res, $out);
    }

    public function create(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $case = $this->service->createCase($actor, $body);
        return ApiResponse::success($res, ['id' => $case->id, 'status' => $case->status], 201);
    }

    public function assign(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $reviewerId = isset($body['reviewer_user_id']) ? (int) $body['reviewer_user_id'] : 0;
        if ($reviewerId <= 0) {
            throw new ValidationException('reviewer_user_id required.');
        }
        $case = $this->service->assign($actor, (string) $args['id'], $reviewerId);
        return ApiResponse::success($res, ['id' => $case->id, 'assigned_reviewer_id' => $case->assigned_reviewer_id]);
    }

    public function transition(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $to = (string) ($body['status'] ?? '');
        $case = $this->service->transition($actor, (string) $args['id'], $to);
        return ApiResponse::success($res, ['id' => $case->id, 'status' => $case->status]);
    }

    public function decide(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $decision = (string) ($body['decision'] ?? '');
        $reason = isset($body['reason']) ? (string) $body['reason'] : null;
        $evidence = isset($body['evidence']) && is_array($body['evidence']) ? $body['evidence'] : [];
        $record = $this->service->decide($actor, (string) $args['id'], $decision, $reason, $evidence);
        return ApiResponse::success($res, [
            'id' => (int) $record->id,
            'decision' => $record->decision,
            'decision_source' => $record->decision_source,
            'decided_at' => $record->decided_at,
        ], 201);
    }

    public function addNote(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $note = (string) ($body['note'] ?? '');
        $private = (bool) ($body['is_private'] ?? true);
        if ($note === '') {
            throw new ValidationException('note is required.');
        }
        $row = $this->service->addNote($actor, (string) $args['id'], $note, $private);
        return ApiResponse::success($res, ['id' => (int) $row->id, 'is_private' => (bool) $row->is_private], 201);
    }

    public function listNotes(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        return ApiResponse::success($res, $this->service->listNotes($actor, (string) $args['id']));
    }

    public function submitReport(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $req->getAttribute('user');
        $body = (array) ($req->getParsedBody() ?? []);
        $r = $this->service->submitReport($actor instanceof User ? $actor : null, $body);
        return ApiResponse::success($res, ['id' => (int) $r->id, 'status' => $r->status], 201);
    }

    public function submitAppeal(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $rationale = (string) ($body['rationale'] ?? '');
        if ($rationale === '') {
            throw new ValidationException('rationale is required.');
        }
        $a = $this->service->submitAppeal($actor, (string) $args['id'], $rationale);
        return ApiResponse::success($res, ['id' => (int) $a->id, 'status' => $a->status], 201);
    }

    public function resolveAppeal(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $outcome = (string) ($body['outcome'] ?? '');
        $reason = isset($body['reason']) ? (string) $body['reason'] : null;
        $a = $this->service->resolveAppeal($actor, (string) $args['id'], $outcome, $reason);
        return ApiResponse::success($res, ['id' => (int) $a->id, 'status' => $a->status]);
    }

    private function currentUser(ServerRequestInterface $req): User
    {
        $u = $req->getAttribute('user');
        if (!$u instanceof User) {
            throw new AuthorizationException();
        }
        return $u;
    }
}
