<?php

declare(strict_types=1);

namespace Meridian\Application\Middleware;

use Meridian\Infrastructure\Metrics\MetricsWriter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wires the deterministic local metrics exporter into the HTTP request path so
 * that per-request counters and duration samples are written to storage/metrics
 * alongside scheduled-job metrics. Only aggregate labels are emitted (method,
 * status, route template) — never body payloads or per-caller identifiers.
 */
final class MetricsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly MetricsWriter $metrics)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);
        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $this->metrics->record('http.request.duration_ms', $durationMs, [
                'method' => $request->getMethod(),
                'status' => 500,
                'outcome' => 'exception',
            ]);
            $this->metrics->record('http.request.count', 1, [
                'method' => $request->getMethod(),
                'status' => 500,
                'outcome' => 'exception',
            ]);
            throw $e;
        }
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $status = $response->getStatusCode();
        $labels = [
            'method' => $request->getMethod(),
            'status' => $status,
            'outcome' => $status >= 500 ? 'server_error' : ($status >= 400 ? 'client_error' : 'ok'),
        ];
        $this->metrics->record('http.request.duration_ms', $durationMs, $labels);
        $this->metrics->record('http.request.count', 1, $labels);
        return $response;
    }
}
