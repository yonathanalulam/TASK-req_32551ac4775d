<?php

declare(strict_types=1);

namespace Meridian\Http\Controllers;

use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Domain\Content\ContentService;
use Meridian\Http\Responses\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ContentController
{
    public function __construct(private readonly ContentService $service)
    {
    }

    public function parse(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->requirePerm($req, 'content.parse');
        $body = (array) ($req->getParsedBody() ?? []);
        $out = $this->service->ingest($body, $actor);
        /** @var \Meridian\Domain\Content\Content $content */
        $content = $out['content'];
        $sections = \Meridian\Domain\Content\ContentSection::query()
            ->where('content_id', $content->content_id)->pluck('tag_slug')->all();
        $mediaRows = \Meridian\Domain\Content\ContentMediaRef::query()
            ->where('content_id', $content->content_id)->orderBy('id')->get()->all();
        $mediaRefs = array_map(static fn($m) => [
            'media_type' => $m->media_type,
            'local_path' => $m->local_path,
            'reference_hash' => $m->reference_hash,
            'external_url' => $m->external_url,
            'caption' => $m->caption,
        ], $mediaRows);
        $automated = $out['automated_moderation'] ?? null;

        $payload = [
            'duplicate' => (bool) $out['duplicate'],
            'content' => [
                'content_id' => $content->content_id,
                'title' => $content->title,
                'body' => $content->body,
                'language' => $content->language,
                'author' => $content->author,
                'published_at' => $content->published_at?->format(DATE_ATOM),
                'media_source' => $content->media_source,
                'section_tags' => $sections,
                'duration_seconds' => $content->duration_seconds !== null ? (int) $content->duration_seconds : null,
                'risk_state' => $content->risk_state,
                'body_length' => mb_strlen($content->body, 'UTF-8'),
                'body_checksum' => $content->body_checksum,
                'ingested_at' => $content->ingested_at?->format(DATE_ATOM),
                'version' => (int) $content->version,
            ],
            'media_refs' => $mediaRefs,
            'automated_moderation' => $automated,
        ];
        return ApiResponse::success($res, $payload, $out['duplicate'] ? 200 : 201);
    }

    public function get(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->requirePerm($req, 'content.view');
        $content = $this->service->get($actor, (string) $args['id']);
        $sections = \Meridian\Domain\Content\ContentSection::query()
            ->where('content_id', $content->content_id)->pluck('tag_slug')->all();
        return ApiResponse::success($res, [
            'content_id' => $content->content_id,
            'title' => $content->title,
            'author' => $content->author,
            'language' => $content->language,
            'media_source' => $content->media_source,
            'duration_seconds' => $content->duration_seconds,
            'published_at' => $content->published_at?->format(DATE_ATOM),
            'ingested_at' => $content->ingested_at?->format(DATE_ATOM),
            'risk_state' => $content->risk_state,
            'section_tags' => $sections,
            'body' => $content->body,
            'body_checksum' => $content->body_checksum,
            'version' => (int) $content->version,
            'merged_into_content_id' => $content->merged_into_content_id,
        ]);
    }

    public function updateMetadata(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->requirePerm($req, 'content.edit_metadata');
        $body = (array) ($req->getParsedBody() ?? []);
        $content = $this->service->updateMetadata($actor, (string) $args['id'], $body);
        return ApiResponse::success($res, [
            'content_id' => $content->content_id,
            'title' => $content->title,
            'risk_state' => $content->risk_state,
            'version' => (int) $content->version,
        ]);
    }

    public function search(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->requirePerm($req, 'content.view');
        $q = $req->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $size = min(100, max(1, (int) ($q['page_size'] ?? 25)));
        $result = $this->service->search($actor, $q, $page, $size);
        return ApiResponse::success($res, $result['items'], 200, [
            'page' => $page, 'page_size' => $size, 'total' => $result['total'],
        ]);
    }

    private function requirePerm(ServerRequestInterface $req, string $perm): User
    {
        $u = $req->getAttribute('user');
        if (!$u instanceof User) {
            throw new AuthorizationException();
        }
        if (!UserPermissions::hasPermission($u, $perm)) {
            throw new AuthorizationException('Missing permission: ' . $perm);
        }
        return $u;
    }
}
