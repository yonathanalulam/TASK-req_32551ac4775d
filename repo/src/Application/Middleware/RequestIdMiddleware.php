<?php

declare(strict_types=1);

namespace Meridian\Application\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $incoming = $request->getHeaderLine('X-Request-Id');
        $rid = $incoming !== '' ? $incoming : Uuid::uuid4()->toString();
        $GLOBALS['MERIDIAN_REQUEST_ID'] = $rid;
        $request = $request->withAttribute('request_id', $rid);
        $response = $handler->handle($request);
        return $response->withHeader('X-Request-Id', $rid);
    }
}
