<?php

declare(strict_types=1);

use Meridian\Domain\Audit\Verification\AuditChainVerifier;

$state = require __DIR__ . '/_bootstrap.php';
/** @var DI\Container $container */
$container = $state['container'];

/** @var AuditChainVerifier $verifier */
$verifier = $container->get(AuditChainVerifier::class);
$day = $argv[1] ?? null;
$result = $verifier->verify($day);
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
exit($result['ok'] ? 0 : 2);
