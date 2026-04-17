<?php

declare(strict_types=1);

namespace Meridian\Http\Responses;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;

/**
 * Produces the standard response envelope defined in section 11.1 of the PRD.
 */
final class ApiResponse
{
    public static function success(ResponseInterface $response, mixed $data, int $status = 200, array $extraMeta = []): ResponseInterface
    {
        return self::write($response, [
            'success' => true,
            'data' => $data,
            'meta' => self::meta($response, $extraMeta),
            'error' => null,
        ], $status);
    }

    /** @param array<string,mixed>|null $details */
    public static function error(
        ResponseInterface $response,
        string $code,
        string $message,
        int $status = 400,
        ?array $details = null,
        array $extraMeta = [],
    ): ResponseInterface {
        $err = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $err['details'] = $details;
        }
        return self::write($response, [
            'success' => false,
            'data' => null,
            'meta' => self::meta($response, $extraMeta),
            'error' => $err,
        ], $status);
    }

    private static function meta(ResponseInterface $response, array $extra): array
    {
        $rid = $response->getHeaderLine('X-Request-Id');
        $meta = [
            'request_id' => $rid !== '' ? $rid : (string) ($GLOBALS['MERIDIAN_REQUEST_ID'] ?? ''),
            'timestamp_utc' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];
        return array_merge($meta, $extra);
    }

    private static function write(ResponseInterface $response, array $body, int $status): ResponseInterface
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload !== false ? $payload : '{}');
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
