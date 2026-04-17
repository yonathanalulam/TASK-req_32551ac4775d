<?php

declare(strict_types=1);

namespace Meridian\Infrastructure\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Structured JSON logs written to storage/logs/app.log. No network sinks.
 */
final class LoggerFactory
{
    /** @param array<string,mixed> $config */
    public static function create(array $config): LoggerInterface
    {
        $path = ($config['storage_path'] ?? sys_get_temp_dir()) . '/logs/app.log';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $logger = new Logger('meridian');
        $handler = new StreamHandler($path, Logger::INFO);
        $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true));
        $logger->pushHandler($handler);
        return $logger;
    }
}
