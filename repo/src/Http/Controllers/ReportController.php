<?php

declare(strict_types=1);

namespace Meridian\Http\Controllers;

use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Reports\ReportService;
use Meridian\Http\Responses\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ReportController
{
    public function __construct(private readonly ReportService $service)
    {
    }

    public function createScheduled(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $s = $this->service->createScheduled($actor, $body);
        return ApiResponse::success($res, ['id' => (int) $s->id, 'key' => $s->key], 201);
    }

    public function listScheduled(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $this->currentUser($req);
        return ApiResponse::success($res, $this->service->listScheduled());
    }

    public function runNow(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $r = $this->service->runNow($actor, (int) $args['id']);
        return ApiResponse::success($res, [
            'id' => (int) $r->id,
            'status' => $r->status,
            'row_count' => (int) ($r->row_count ?? 0),
            'expires_at' => $r->expires_at,
        ], 201);
    }

    public function listGenerated(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $q = $req->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $size = min(100, max(1, (int) ($q['page_size'] ?? 25)));
        $out = $this->service->listGenerated($actor, $page, $size);
        return ApiResponse::success($res, $out['items'], 200, ['page' => $page, 'page_size' => $size, 'total' => $out['total']]);
    }

    public function getGenerated(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        return ApiResponse::success($res, $this->service->getGenerated($actor, (int) $args['id']));
    }

    public function download(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $info = $this->service->downloadPath($actor, (int) $args['id']);
        $contents = file_get_contents($info['absolute_path']);
        $res = $res
            ->withHeader('Content-Type', $info['format'] === 'csv' ? 'text/csv' : 'application/json')
            ->withHeader('Content-Length', (string) $info['size_bytes'])
            ->withHeader('X-Meridian-Checksum', $info['checksum'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($info['relative_path']) . '"');
        $res->getBody()->write($contents !== false ? $contents : '');
        return $res;
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
