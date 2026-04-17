<?php

declare(strict_types=1);

$env = static function (string $key, $default = null) {
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    return $v;
};

return [
    'env' => $env('APP_ENV', 'production'),
    'debug' => filter_var($env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
    'timezone' => $env('APP_TIMEZONE', 'UTC'),
    'storage_path' => dirname(__DIR__) . '/storage',
    'export_root' => dirname(__DIR__) . '/' . ltrim((string) $env('EXPORT_ROOT', 'storage/exports'), '/'),
    'report_root' => dirname(__DIR__) . '/' . ltrim((string) $env('REPORT_ROOT', 'storage/reports'), '/'),
    'metrics_root' => dirname(__DIR__) . '/' . ltrim((string) $env('METRICS_ROOT', 'storage/metrics'), '/'),

    'crypto' => [
        'master_key_hex' => (string) $env('APP_MASTER_KEY', ''),
        'master_key_version' => (int) $env('APP_MASTER_KEY_VERSION', '1'),
        // Prior keys in form "version:hex" for rotation.
        'previous_keys' => array_values(array_filter(explode(',', (string) $env('APP_PREVIOUS_KEYS', '')))),
    ],

    'session' => [
        'absolute_ttl_seconds' => (int) $env('SESSION_ABSOLUTE_TTL', '43200'),
        'idle_ttl_seconds' => (int) $env('SESSION_IDLE_TTL', '7200'),
        'max_concurrent' => (int) $env('SESSION_MAX_CONCURRENT', '5'),
    ],

    'rate_limit' => [
        'default_per_minute' => (int) $env('RATE_LIMIT_DEFAULT', '60'),
    ],

    'lockout' => [
        'login_failures_threshold' => 5,
        'login_window_seconds' => 15 * 60,
        'login_lock_seconds' => 30 * 60,
        'reset_failures_threshold' => 5,
        'reset_window_seconds' => 30 * 60,
        'reset_lock_seconds' => 60 * 60,
    ],

    'sla' => [
        'business_hours' => [
            'start' => '09:00',
            'end' => '17:00',
            'weekdays' => [1, 2, 3, 4, 5],
        ],
        'moderation_initial_hours' => 24,
    ],

    'parsing' => [
        'language_confidence_threshold' => 0.75,
        'body_min_length' => 200,
        'title_max_length' => 180,
        'section_tags_max' => 10,
    ],

    'dedup' => [
        'auto_merge_similarity' => 0.92,
        'review_similarity_min' => 0.85,
    ],

    'moderation' => [
        'ad_link_density_max' => 3.0, // per 1000 chars
    ],

    'analytics' => [
        'idempotency_window_hours' => 24,
        'dwell_cap_seconds' => 14400,
        'raw_retention_days' => 365,
    ],

    'retention' => [
        'generated_reports_days' => 90,
        'idempotency_keys_hours' => 48,
        'expired_sessions_days' => 30,
    ],

    'jobs' => [
        'max_retries' => 3,
        'backoff_seconds' => [60, 300, 900],
        'stale_running_seconds' => 1800,
    ],

    'pagination' => [
        'default_page_size' => 25,
        'max_default' => 100,
        'hard_max' => 500,
    ],
];
