<?php

declare(strict_types=1);

namespace Meridian\Http\Controllers;

use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Domain\Dedup\DedupService;
use Meridian\Http\Responses\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DedupController
{
    public function __construct(private readonly DedupService $service)
    {
    }

    public function list(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $q = $req->getQueryParams();
        $status = isset($q['status']) ? (string) $q['status'] : null;
        $page = max(1, (int) ($q['page'] ?? 1));
        $size = min(100, max(1, (int) ($q['page_size'] ?? 25)));
        $out = $this->service->listCandidates($actor, $status, $page, $size);
        return ApiResponse::success($res, $out['items'], 200, ['page' => $page, 'page_size' => $size, 'total' => $out['total']]);
    }

    public function merge(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $primary = (string) ($body['primary_content_id'] ?? '');
        $secondary = (string) ($body['secondary_content_id'] ?? '');
        $reason = isset($body['reason']) ? (string) $body['reason'] : null;
        if ($primary === '' || $secondary === '') {
            throw new ValidationException('primary_content_id and secondary_content_id are required.');
        }
        $out = $this->service->merge($actor, $primary, $secondary, $reason);
        return ApiResponse::success($res, $out, 200);
    }

    public function unmerge(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $secondary = (string) ($body['secondary_content_id'] ?? '');
        $reason = isset($body['reason']) ? (string) $body['reason'] : null;
        if ($secondary === '') {
            throw new ValidationException('secondary_content_id is required.');
        }
        $out = $this->service->unmerge($actor, $secondary, $reason);
        return ApiResponse::success($res, $out, 200);
    }

    public function recompute(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->currentUser($req);
        if (!UserPermissions::hasRole($actor, 'administrator')) {
            throw new AuthorizationException('Administrator role required.');
        }
        $count = $this->service->recompute(1000);
        return ApiResponse::success($res, ['candidates' => $count]);
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
