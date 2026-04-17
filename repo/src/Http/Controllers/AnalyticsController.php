<?php

declare(strict_types=1);

namespace Meridian\Http\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Analytics\AnalyticsService;
use Meridian\Domain\Auth\User;
use Meridian\Http\Responses\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AnalyticsController
{
    public function __construct(private readonly AnalyticsService $service)
    {
    }

    public function ingest(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $req->getAttribute('user');
        if (!$actor instanceof User) {
            throw new AuthorizationException();
        }
        $body = (array) ($req->getParsedBody() ?? []);
        if (empty($body['idempotency_key']) && $req->hasHeader('Idempotency-Key')) {
            $body['idempotency_key'] = $req->getHeaderLine('Idempotency-Key');
        }
        $sp = $req->getServerParams();
        $ip = $sp['REMOTE_ADDR'] ?? null;
        $out = $this->service->ingest($body, $actor, $ip);
        return ApiResponse::success($res, [
            'id' => (int) $out['event']->id,
            'idempotency_key' => $out['event']->idempotency_key,
        ], 201);
    }

    public function query(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $q = $req->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $size = min(500, max(1, (int) ($q['page_size'] ?? 50)));
        $out = $this->service->query($actor, $q, $page, $size);
        return ApiResponse::success($res, $out['items'], 200, ['page' => $page, 'page_size' => $size, 'total' => $out['total']]);
    }

    public function funnel(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $stages = isset($body['stages']) && is_array($body['stages']) ? $body['stages'] : [];
        if ($stages === []) {
            throw new ValidationException('stages is required and must be a non-empty array.');
        }
        $from = isset($body['from']) ? new DateTimeImmutable((string) $body['from'], new DateTimeZone('UTC')) : (new DateTimeImmutable('now -7 days', new DateTimeZone('UTC')));
        $to = isset($body['to']) ? new DateTimeImmutable((string) $body['to'], new DateTimeZone('UTC')) : (new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $normStages = [];
        foreach ($stages as $s) {
            if (!is_array($s) || empty($s['event_type'])) {
                throw new ValidationException('Each stage must include event_type.');
            }
            $normStages[] = ['event_type' => (string) $s['event_type'], 'object_type' => $s['object_type'] ?? null];
        }
        $out = $this->service->funnel($actor, $normStages, $from, $to);
        return ApiResponse::success($res, $out);
    }

    public function kpis(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $q = $req->getQueryParams();
        $from = isset($q['from']) ? new DateTimeImmutable((string) $q['from'], new DateTimeZone('UTC')) : (new DateTimeImmutable('now -30 days', new DateTimeZone('UTC')));
        $to = isset($q['to']) ? new DateTimeImmutable((string) $q['to'], new DateTimeZone('UTC')) : (new DateTimeImmutable('now', new DateTimeZone('UTC')));
        return ApiResponse::success($res, $this->service->kpiSummary($actor, $from, $to));
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
