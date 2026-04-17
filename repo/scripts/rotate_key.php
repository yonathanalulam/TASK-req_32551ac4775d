<?php

declare(strict_types=1);

/**
 * Rotates the AES master key by re-encrypting user_security_answers with the current
 * key. Requires the old key(s) to be configured in APP_PREVIOUS_KEYS so decrypt() still works.
 */

use Meridian\Domain\Auth\UserSecurityAnswer;
use Meridian\Infrastructure\Crypto\Cipher;

$state = require __DIR__ . '/_bootstrap.php';
/** @var DI\Container $container */
$container = $state['container'];
/** @var Cipher $cipher */
$cipher = $container->get(Cipher::class);

$updated = 0;
foreach (UserSecurityAnswer::query()->cursor() as $row) {
    try {
        $plain = $cipher->decrypt((string) $row->answer_ciphertext);
    } catch (Throwable $e) {
        fwrite(STDERR, "Skipping user_security_answer id={$row->id}: " . $e->getMessage() . "\n");
        continue;
    }
    $row->answer_ciphertext = $cipher->encrypt($plain);
    $row->key_version = $cipher->currentKeyVersion();
    $row->save();
    $updated++;
}
fwrite(STDOUT, "Re-encrypted {$updated} security answer records with key version {$cipher->currentKeyVersion()}.\n");
