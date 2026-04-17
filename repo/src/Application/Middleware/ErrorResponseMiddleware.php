<?php

declare(strict_types=1);

namespace Meridian\Application\Middleware;

use Meridian\Application\Exceptions\ApiException;
use Meridian\Http\Responses\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;
use Throwable;

/**
 * Central exception funnel. Converts ApiException into standardized error envelope and
 * unexpected exceptions into a generic 500 (with detail only when debug=true).
 */
final class ErrorResponseMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly bool $debug)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ApiException $e) {
            $response = (new ResponseFactory())->createResponse();
            return ApiResponse::error($response, $e->errorCode(), $e->getMessage(), $e->httpStatus(), $e->details());
        } catch (HttpNotFoundException $e) {
            $response = (new ResponseFactory())->createResponse();
            return ApiResponse::error($response, 'NOT_FOUND', 'Route not found.', 404);
        } catch (HttpMethodNotAllowedException $e) {
            $response = (new ResponseFactory())->createResponse();
            return ApiResponse::error($response, 'METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
        } catch (Throwable $e) {
            $response = (new ResponseFactory())->createResponse();
            $details = $this->debug ? [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ] : null;
            return ApiResponse::error($response, 'INTERNAL_ERROR', 'An unexpected error occurred.', 500, $details);
        }
    }
}
