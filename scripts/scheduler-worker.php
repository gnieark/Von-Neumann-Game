<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Repository\ScheduledEventRepository;

require_once __DIR__ . '/../vendor/autoload.php';

$factory = new AppFactory(dirname(__DIR__));
$appConfig = $factory->appConfig();
$limit = max(1, (int) ($appConfig['schedulerProcessLimit'] ?? 100));
$maximumSleepSeconds = 5;
$leaseSeconds = max(60, (int) ($appConfig['schedulerLeaseSeconds'] ?? 3600));
$recoveryIntervalSeconds = max(10, (int) ($appConfig['schedulerRecoveryIntervalSeconds'] ?? 60));

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, (int) substr($argument, strlen('--limit=')));
    }
    if (str_starts_with($argument, '--maximum-sleep=')) {
        $maximumSleepSeconds = max(1, (int) substr($argument, strlen('--maximum-sleep=')));
    }
    if (str_starts_with($argument, '--lease-seconds=')) {
        $leaseSeconds = max(60, (int) substr($argument, strlen('--lease-seconds=')));
    }
}

$running = true;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

$pdo = $factory->pdo();
$scheduler = $factory->schedulerService($pdo);
$events = new ScheduledEventRepository($pdo);

fwrite(STDOUT, sprintf("[%s] scheduler worker started\n", gmdate('c')));
$lastRecoveryAt = 0;

while ($running) {
    if (time() - $lastRecoveryAt >= $recoveryIntervalSeconds) {
        $recovered = $scheduler->recoverExpiredRunningEvents($leaseSeconds);
        $lastRecoveryAt = time();
        if ($recovered > 0) {
            fwrite(STDOUT, sprintf("[%s] recovered expired scheduled events: %d\n", gmdate('c'), $recovered));
        }
    }
    $stats = $scheduler->processDueEvents($limit);

    if ($stats['processed'] > 0 || $stats['deferred'] > 0 || $stats['failed'] > 0) {
        fwrite(
            $stats['failed'] > 0 ? STDERR : STDOUT,
            sprintf(
                "[%s] scheduled events: due=%d processed=%d deferred=%d failed=%d\n",
                gmdate('c'),
                $stats['due'],
                $stats['processed'],
                $stats['deferred'],
                $stats['failed'],
            ),
        );
    }

    if (!$running) {
        break;
    }

    if ($stats['due'] >= $limit) {
        continue;
    }

    $nextRunAt = $events->findNextPendingRunAt();
    $sleepSeconds = $maximumSleepSeconds;
    if ($nextRunAt !== null) {
        try {
            $secondsUntilNextRun = (new DateTimeImmutable($nextRunAt))->getTimestamp() - time();
            $sleepSeconds = max(0, min($maximumSleepSeconds, $secondsUntilNextRun));
        } catch (Throwable $error) {
            fwrite(STDERR, sprintf("[%s] invalid scheduled event date: %s\n", gmdate('c'), $error->getMessage()));
        }
    }

    if ($sleepSeconds > 0) {
        sleep($sleepSeconds);
    }
}

fwrite(STDOUT, sprintf("[%s] scheduler worker stopped\n", gmdate('c')));
