<?php

declare(strict_types=1);

namespace Meridian\Http\Controllers;

use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Moderation\RulePack;
use Meridian\Domain\Moderation\RulePackRule;
use Meridian\Domain\Moderation\RulePackService;
use Meridian\Domain\Moderation\RulePackVersion;
use Meridian\Http\Responses\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RulePackController
{
    public function __construct(private readonly RulePackService $service)
    {
    }

    public function list(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $this->currentUser($req);
        $packs = RulePack::query()->orderBy('key')->get()->all();
        $out = [];
        foreach ($packs as $p) {
            $versions = RulePackVersion::query()
                ->where('rule_pack_id', $p->id)
                ->orderBy('version')
                ->get(['id', 'version', 'status', 'published_at'])->all();
            $out[] = [
                'id' => (int) $p->id,
                'key' => $p->key,
                'description' => $p->description,
                'versions' => array_map(static fn($v) => [
                    'id' => (int) $v->id,
                    'version' => (int) $v->version,
                    'status' => $v->status,
                    'published_at' => $v->published_at,
                ], $versions),
            ];
        }
        return ApiResponse::success($res, $out);
    }

    public function create(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $key = (string) ($body['key'] ?? '');
        $desc = isset($body['description']) ? (string) $body['description'] : null;
        $pack = $this->service->createPack($actor, $key, $desc);
        return ApiResponse::success($res, ['id' => (int) $pack->id, 'key' => $pack->key], 201);
    }

    public function createVersion(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $v = $this->service->createDraftVersion($actor, (int) $args['id'], $body['notes'] ?? null);
        return ApiResponse::success($res, [
            'id' => (int) $v->id,
            'version' => (int) $v->version,
            'status' => $v->status,
        ], 201);
    }

    public function addRule(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $body = (array) ($req->getParsedBody() ?? []);
        $rule = $this->service->addRule($actor, (int) $args['versionId'], $body);
        return ApiResponse::success($res, [
            'id' => (int) $rule->id,
            'rule_kind' => $rule->rule_kind,
            'severity' => $rule->severity,
        ], 201);
    }

    public function publishVersion(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $v = $this->service->publishVersion($actor, (int) $args['versionId']);
        return ApiResponse::success($res, [
            'id' => (int) $v->id,
            'version' => (int) $v->version,
            'status' => $v->status,
            'published_at' => $v->published_at,
        ]);
    }

    public function archiveVersion(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $v = $this->service->archiveVersion($actor, (int) $args['versionId']);
        return ApiResponse::success($res, ['id' => (int) $v->id, 'status' => $v->status]);
    }

    public function getVersion(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $this->currentUser($req);
        $v = RulePackVersion::query()->find((int) $args['versionId']);
        if (!$v instanceof RulePackVersion) {
            throw new ValidationException('Version not found.');
        }
        $rules = RulePackRule::query()->where('rule_pack_version_id', (int) $v->id)->get()->all();
        return ApiResponse::success($res, [
            'id' => (int) $v->id,
            'rule_pack_id' => $v->rule_pack_id,
            'version' => (int) $v->version,
            'status' => $v->status,
            'published_at' => $v->published_at,
            'rules' => array_map(static fn($r) => [
                'id' => (int) $r->id,
                'rule_kind' => $r->rule_kind,
                'pattern' => $r->pattern,
                'threshold' => $r->threshold !== null ? (float) $r->threshold : null,
                'severity' => $r->severity,
                'description' => $r->description,
            ], $rules),
        ]);
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
