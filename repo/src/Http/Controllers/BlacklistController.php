<?php

declare(strict_types=1);

namespace Meridian\Http\Controllers;

use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Domain\Blacklist\BlacklistService;
use Meridian\Http\Responses\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class BlacklistController
{
    public function __construct(private readonly BlacklistService $service)
    {
    }

    public function list(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->requireRead($req);
        $q = $req->getQueryParams();
        $type = isset($q['type']) ? (string) $q['type'] : null;
        $page = max(1, (int) ($q['page'] ?? 1));
        $size = min(100, max(1, (int) ($q['page_size'] ?? 25)));
        $rows = $this->service->listActive($type, $page, $size);
        return ApiResponse::success($res, $rows, 200, ['page' => $page, 'page_size' => $size]);
    }

    public function add(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->requireManage($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $type = (string) ($body['entry_type'] ?? '');
        $key = (string) ($body['target_key'] ?? '');
        $reason = isset($body['reason']) ? (string) $body['reason'] : null;
        if ($type === '' || $key === '') {
            throw new ValidationException('entry_type and target_key are required.');
        }
        $entry = $this->service->add($actor, $type, $key, $reason);
        return ApiResponse::success($res, [
            'id' => (int) $entry->id,
            'entry_type' => $entry->entry_type,
            'target_key' => $entry->target_key,
            'reason' => $entry->reason,
            'created_at' => $entry->created_at,
        ], 201);
    }

    public function revoke(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->requireManage($req);
        $this->service->revoke($actor, (int) $args['id']);
        return ApiResponse::success($res, ['revoked' => true]);
    }

    private function requireRead(ServerRequestInterface $req): User
    {
        $u = $req->getAttribute('user');
        if (!$u instanceof User) {
            throw new AuthorizationException();
        }
        if (!UserPermissions::hasPermission($u, 'governance.manage_blacklists') &&
            !UserPermissions::hasPermission($u, 'governance.view_audit')) {
            throw new AuthorizationException('Missing permission to view blacklists.');
        }
        return $u;
    }

    private function requireManage(ServerRequestInterface $req): User
    {
        $u = $req->getAttribute('user');
        if (!$u instanceof User) {
            throw new AuthorizationException();
        }
        if (!UserPermissions::hasPermission($u, 'governance.manage_blacklists')) {
            throw new AuthorizationException('Missing permission: governance.manage_blacklists');
        }
        return $u;
    }
}
