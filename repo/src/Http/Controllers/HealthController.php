<?php

declare(strict_types=1);

namespace Meridian\Http\Controllers;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Http\Responses\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HealthController
{
    public function get(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $dbOk = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbOk = false;
        }
        return ApiResponse::success($res, [
            'status' => $dbOk ? 'ok' : 'degraded',
            'database' => $dbOk ? 'reachable' : 'unreachable',
        ], $dbOk ? 200 : 503);
    }
}
