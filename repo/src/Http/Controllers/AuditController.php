<?php

declare(strict_types=1);

namespace Meridian\Http\Controllers;

use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Domain\Audit\AuditHashChain;
use Meridian\Domain\Audit\AuditLog;
use Meridian\Domain\Audit\Verification\AuditChainVerifier;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Http\Responses\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuditController
{
    public function __construct(private readonly AuditChainVerifier $verifier)
    {
    }

    public function list(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $this->requireView($req);
        $q = $req->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $size = min(100, max(1, (int) ($q['page_size'] ?? 25)));
        $query = AuditLog::query()->orderByDesc('id');
        if (!empty($q['action'])) {
            $query->where('action', (string) $q['action']);
        }
        if (!empty($q['object_type'])) {
            $query->where('object_type', (string) $q['object_type']);
        }
        if (!empty($q['object_id'])) {
            $query->where('object_id', (string) $q['object_id']);
        }
        if (!empty($q['actor_id'])) {
            $query->where('actor_id', (string) $q['actor_id']);
        }
        $total = (clone $query)->count();
        $rows = $query->forPage($page, $size)->get()->all();
        return ApiResponse::success($res, array_map(static fn(AuditLog $l) => [
            'id' => (int) $l->id,
            'occurred_at' => $l->occurred_at,
            'actor_type' => $l->actor_type,
            'actor_id' => $l->actor_id,
            'action' => $l->action,
            'object_type' => $l->object_type,
            'object_id' => $l->object_id,
            'request_id' => $l->request_id,
            'row_hash' => $l->row_hash,
            'payload' => json_decode((string) $l->payload_json, true),
        ], $rows), 200, ['page' => $page, 'page_size' => $size, 'total' => $total]);
    }

    public function chain(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $this->requireView($req);
        $q = $req->getQueryParams();
        $limit = min(90, max(1, (int) ($q['limit'] ?? 30)));
        $rows = AuditHashChain::query()->orderByDesc('chain_date')->limit($limit)->get()->all();
        return ApiResponse::success($res, array_map(fn(AuditHashChain $c) => [
            'chain_date' => $c->chain_date,
            'previous_day_hash' => $c->previous_day_hash,
            'first_log_id' => $c->first_log_id,
            'last_log_id' => $c->last_log_id,
            'row_count' => $c->row_count,
            'chain_hash' => $c->chain_hash,
            'finalized_at' => $c->finalized_at,
            'finalized_by' => $c->finalized_by,
        ], $rows));
    }

    public function verify(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $this->requireAdmin($req);
        $q = $req->getQueryParams();
        $day = isset($q['day']) ? (string) $q['day'] : null;
        $result = $this->verifier->verify($day);
        return ApiResponse::success($res, $result);
    }

    private function requireView(ServerRequestInterface $req): User
    {
        $u = $req->getAttribute('user');
        if (!$u instanceof User) {
            throw new AuthorizationException();
        }
        if (!UserPermissions::hasPermission($u, 'governance.view_audit')) {
            throw new AuthorizationException('Missing permission: governance.view_audit');
        }
        return $u;
    }

    private function requireAdmin(ServerRequestInterface $req): User
    {
        $u = $req->getAttribute('user');
        if (!$u instanceof User) {
            throw new AuthorizationException();
        }
        if (!UserPermissions::hasRole($u, 'administrator')) {
            throw new AuthorizationException('Administrator role required.');
        }
        return $u;
    }
}
