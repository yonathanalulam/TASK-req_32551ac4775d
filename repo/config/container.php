<?php

declare(strict_types=1);

use DI\Container;
use Meridian\Application\Middleware\AuthMiddleware;
use Meridian\Application\Middleware\MetricsMiddleware;
use Meridian\Application\Middleware\RateLimitMiddleware;
use Meridian\Domain\Analytics\AnalyticsService;
use Meridian\Domain\Analytics\Jobs\IdempotencyCleanupJob;
use Meridian\Domain\Analytics\Jobs\RollupJob;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Audit\Jobs\FinalizeAuditChainJob;
use Meridian\Domain\Auth\AuthService;
use Meridian\Domain\Auth\Jobs\ExpiredSessionsJob;
use Meridian\Domain\Auth\LockoutPolicy;
use Meridian\Domain\Auth\PasswordHasher;
use Meridian\Domain\Auth\SessionService;
use Meridian\Domain\Blacklist\BlacklistService;
use Meridian\Domain\Content\ContentService;
use Meridian\Domain\Content\Parsing\HtmlDenoiser;
use Meridian\Domain\Content\Parsing\LanguageDetector;
use Meridian\Domain\Content\Parsing\NormalizationPipeline;
use Meridian\Domain\Content\Parsing\SectionTagNormalizer;
use Meridian\Domain\Content\Parsing\UrlStripper;
use Meridian\Domain\Dedup\DedupService;
use Meridian\Domain\Dedup\FingerprintService;
use Meridian\Domain\Dedup\Jobs\RecomputeDedupCandidatesJob;
use Meridian\Domain\Events\EventService;
use Meridian\Domain\Jobs\JobRunner;
use Meridian\Domain\Authorization\Policy;
use Meridian\Domain\Moderation\AutomatedModerator;
use Meridian\Domain\Moderation\ModerationService;
use Meridian\Domain\Moderation\RuleEvaluator;
use Meridian\Domain\Moderation\RulePackService;
use Meridian\Domain\Ops\Jobs\LogRotationJob;
use Meridian\Domain\Ops\Jobs\MetricsSnapshotJob;
use Meridian\Domain\Reports\Jobs\ReportRetentionJob;
use Meridian\Domain\Reports\ReportService;
use Meridian\Infrastructure\Clock\Clock;
use Meridian\Infrastructure\Clock\SystemClock;
use Meridian\Infrastructure\Crypto\AesGcmCipher;
use Meridian\Infrastructure\Crypto\Cipher;
use Meridian\Infrastructure\Logging\LoggerFactory;
use Meridian\Infrastructure\Metrics\MetricsWriter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [
    LoggerInterface::class => static fn(ContainerInterface $c) => LoggerFactory::create($c->get('config')),
    Clock::class => static fn() => new SystemClock(),
    MetricsWriter::class => static function (ContainerInterface $c): MetricsWriter {
        $config = $c->get('config');
        $root = (string) ($config['metrics_root'] ?? ($config['storage_path'] . '/metrics'));
        return new MetricsWriter($c->get(Clock::class), $root);
    },
    Cipher::class => static function (ContainerInterface $c): Cipher {
        $cfg = $c->get('config')['crypto'];
        return new AesGcmCipher(
            (string) $cfg['master_key_hex'],
            (int) $cfg['master_key_version'],
            (array) $cfg['previous_keys'],
        );
    },
    PasswordHasher::class => static fn() => new PasswordHasher(),
    AuditLogger::class => static fn(ContainerInterface $c) => new AuditLogger($c->get(Clock::class)),
    SessionService::class => static function (ContainerInterface $c): SessionService {
        $cfg = $c->get('config')['session'];
        return new SessionService(
            $c->get(Clock::class),
            $c->get(Cipher::class),
            (int) $cfg['absolute_ttl_seconds'],
            (int) $cfg['idle_ttl_seconds'],
            (int) $cfg['max_concurrent'],
        );
    },
    LockoutPolicy::class => static function (ContainerInterface $c): LockoutPolicy {
        $cfg = $c->get('config')['lockout'];
        return new LockoutPolicy(
            (int) $cfg['login_failures_threshold'],
            (int) $cfg['login_window_seconds'],
            (int) $cfg['login_lock_seconds'],
            (int) $cfg['reset_failures_threshold'],
            (int) $cfg['reset_window_seconds'],
            (int) $cfg['reset_lock_seconds'],
        );
    },
    AuthService::class => static fn(ContainerInterface $c) => new AuthService(
        $c->get(Clock::class),
        $c->get(PasswordHasher::class),
        $c->get(SessionService::class),
        $c->get(LockoutPolicy::class),
        $c->get(Cipher::class),
        $c->get(AuditLogger::class),
    ),
    BlacklistService::class => static fn(ContainerInterface $c) => new BlacklistService(
        $c->get(Clock::class),
        $c->get(AuditLogger::class),
    ),

    AuthMiddleware::class => static fn(ContainerInterface $c) => new AuthMiddleware(
        $c->get(SessionService::class),
        $c->get(BlacklistService::class),
        $c->get(AuditLogger::class),
    ),
    RateLimitMiddleware::class => static function (ContainerInterface $c): RateLimitMiddleware {
        $cfg = $c->get('config')['rate_limit'];
        return new RateLimitMiddleware($c->get(Clock::class), (int) $cfg['default_per_minute']);
    },
    MetricsMiddleware::class => static fn(ContainerInterface $c) => new MetricsMiddleware($c->get(MetricsWriter::class)),

    JobRunner::class => static function (ContainerInterface $c): JobRunner {
        $cfg = $c->get('config')['jobs'];
        return new JobRunner(
            $c,
            $c->get(Clock::class),
            $c->get(LoggerInterface::class),
            $c->get(AuditLogger::class),
            (int) $cfg['max_retries'],
            (array) $cfg['backoff_seconds'],
            (int) $cfg['stale_running_seconds'],
            $c->get(MetricsWriter::class),
        );
    },

    // Content / parsing
    HtmlDenoiser::class => static fn() => new HtmlDenoiser(),
    UrlStripper::class => static fn() => new UrlStripper(),
    SectionTagNormalizer::class => static fn() => new SectionTagNormalizer(),
    LanguageDetector::class => static fn(ContainerInterface $c) => new LanguageDetector((float) $c->get('config')['parsing']['language_confidence_threshold']),
    NormalizationPipeline::class => static fn(ContainerInterface $c) => new NormalizationPipeline(
        $c->get(HtmlDenoiser::class),
        $c->get(UrlStripper::class),
        $c->get(SectionTagNormalizer::class),
        $c->get(LanguageDetector::class),
        $c->get('config')['parsing'],
    ),
    FingerprintService::class => static fn() => new FingerprintService(),
    ContentService::class => static fn(ContainerInterface $c) => new ContentService(
        $c->get(Clock::class),
        $c->get(NormalizationPipeline::class),
        $c->get(FingerprintService::class),
        $c->get(AuditLogger::class),
        $c->get(BlacklistService::class),
        $c->get(AutomatedModerator::class),
        $c->get(Policy::class),
    ),
    AutomatedModerator::class => static fn(ContainerInterface $c) => new AutomatedModerator(
        $c->get(RuleEvaluator::class),
        $c->get(ModerationService::class),
        $c->get(AuditLogger::class),
    ),
    Policy::class => static fn(ContainerInterface $c) => new Policy(),
    DedupService::class => static fn(ContainerInterface $c) => new DedupService(
        $c->get(Clock::class),
        $c->get(FingerprintService::class),
        $c->get(AuditLogger::class),
        $c->get('config')['dedup'],
        $c->get(Policy::class),
        $c->get(BlacklistService::class),
    ),

    // Moderation
    RuleEvaluator::class => static fn(ContainerInterface $c) => new RuleEvaluator((float) $c->get('config')['moderation']['ad_link_density_max']),
    RulePackService::class => static fn(ContainerInterface $c) => new RulePackService(
        $c->get(Clock::class),
        $c->get(AuditLogger::class),
    ),
    ModerationService::class => static fn(ContainerInterface $c) => new ModerationService(
        $c->get(Clock::class),
        $c->get(RuleEvaluator::class),
        $c->get(AuditLogger::class),
        $c->get('config')['sla'],
        $c->get(Policy::class),
    ),

    // Events
    EventService::class => static fn(ContainerInterface $c) => new EventService(
        $c->get(Clock::class),
        $c->get(AuditLogger::class),
        $c->get(Policy::class),
    ),

    // Analytics / reports
    AnalyticsService::class => static fn(ContainerInterface $c) => new AnalyticsService(
        $c->get(Clock::class),
        $c->get(Cipher::class),
        $c->get(AuditLogger::class),
        $c->get('config')['analytics'],
        $c->get(Policy::class),
        $c->get(BlacklistService::class),
    ),
    ReportService::class => static fn(ContainerInterface $c) => new ReportService(
        $c->get(Clock::class),
        $c->get(AuditLogger::class),
        $c->get('config'),
        $c->get(Policy::class),
    ),

    // Job handlers (must be DI-instantiable)
    FinalizeAuditChainJob::class => static fn(ContainerInterface $c) => new FinalizeAuditChainJob($c->get(Clock::class)),
    ReportRetentionJob::class => static fn(ContainerInterface $c) => new ReportRetentionJob(
        $c->get(Clock::class),
        $c->get(AuditLogger::class),
        (string) $c->get('config')['report_root'],
        (int) $c->get('config')['retention']['generated_reports_days'],
    ),
    RecomputeDedupCandidatesJob::class => static fn(ContainerInterface $c) => new RecomputeDedupCandidatesJob(
        $c->get(DedupService::class),
    ),
    ExpiredSessionsJob::class => static fn(ContainerInterface $c) => new ExpiredSessionsJob($c->get(Clock::class)),
    IdempotencyCleanupJob::class => static fn(ContainerInterface $c) => new IdempotencyCleanupJob($c->get(Clock::class)),
    RollupJob::class => static fn(ContainerInterface $c) => new RollupJob($c->get(Clock::class)),
    LogRotationJob::class => static fn(ContainerInterface $c) => new LogRotationJob((string) $c->get('config')['storage_path']),
    MetricsSnapshotJob::class => static fn(ContainerInterface $c) => new MetricsSnapshotJob(
        $c->get(Clock::class),
        $c->get(MetricsWriter::class),
    ),
];
