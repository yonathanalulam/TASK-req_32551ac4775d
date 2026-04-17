<?php

declare(strict_types=1);

namespace Meridian\Http\Controllers;

use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Events\EventService;
use Meridian\Http\Responses\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class EventController
{
    public function __construct(private readonly EventService $service)
    {
    }

    public function create(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $out = $this->service->createEvent($actor, $body);
        return ApiResponse::success($res, [
            'event_id' => $out['event']->event_id,
            'initial_version_id' => (int) $out['initial_version']->id,
        ], 201);
    }

    public function list(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $this->currentUser($req);
        $q = $req->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $size = min(100, max(1, (int) ($q['page_size'] ?? 25)));
        $out = $this->service->listEvents($req->getAttribute('user'), $q, $page, $size);
        return ApiResponse::success($res, $out['items'], 200, ['page' => $page, 'page_size' => $size, 'total' => $out['total']]);
    }

    public function get(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        return ApiResponse::success($res, $this->service->getEvent($actor, (string) $args['id']));
    }

    public function createDraftVersion(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $base = isset($body['base_config']) && is_array($body['base_config']) ? $body['base_config'] : null;
        $v = $this->service->createDraftVersion($actor, (string) $args['id'], $base);
        return ApiResponse::success($res, ['id' => (int) $v->id, 'version' => (int) $v->version, 'status' => $v->status], 201);
    }

    public function updateDraft(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $v = $this->service->updateDraft($actor, (string) $args['id'], (int) $args['versionId'], $body);
        return ApiResponse::success($res, [
            'id' => (int) $v->id,
            'version' => (int) $v->version,
            'draft_version_number' => (int) $v->draft_version_number,
        ]);
    }

    public function publishVersion(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $v = $this->service->publishVersion($actor, (string) $args['id'], (int) $args['versionId']);
        return ApiResponse::success($res, ['id' => (int) $v->id, 'status' => $v->status, 'published_at' => $v->published_at?->format(DATE_ATOM)]);
    }

    public function rollbackVersion(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $rationale = isset($body['rationale']) ? (string) $body['rationale'] : null;
        $v = $this->service->rollback($actor, (string) $args['id'], (int) $args['versionId'], $rationale);
        return ApiResponse::success($res, ['id' => (int) $v->id, 'status' => $v->status]);
    }

    public function cancelVersion(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $rationale = isset($body['rationale']) ? (string) $body['rationale'] : null;
        $v = $this->service->cancel($actor, (string) $args['id'], (int) $args['versionId'], $rationale);
        return ApiResponse::success($res, ['id' => (int) $v->id, 'status' => $v->status]);
    }

    public function getVersion(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        return ApiResponse::success($res, $this->service->getVersion($actor, (string) $args['id'], (int) $args['versionId']));
    }

    public function addBinding(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $b = $this->service->addBinding($actor, (string) $args['id'], (int) $args['versionId'], $body);
        return ApiResponse::success($res, [
            'id' => (int) $b->id,
            'binding_type' => $b->binding_type,
            'venue_id' => $b->venue_id,
            'equipment_id' => $b->equipment_id,
            'quantity' => (int) $b->quantity,
        ], 201);
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
