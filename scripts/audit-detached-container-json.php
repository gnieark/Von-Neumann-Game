<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Service\DetachedContainerJsonAuditService;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    exit(auditDetachedContainerJsonRun($argv));
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . auditDetachedContainerJsonUsage());
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to audit detached container JSON: ' . $e->getMessage() . "\n");
    exit(2);
}

/**
 * @param array<int, string> $argv
 */
function auditDetachedContainerJsonRun(array $argv): int
{
    $options = auditDetachedContainerJsonParseArguments($argv);
    if ($options['help']) {
        echo auditDetachedContainerJsonUsage();
        return 0;
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $appConfig = $factory->appConfig();
    $configuredPath = (string) ($appConfig['universePath'] ?? 'data/universe');
    $universePath = auditDetachedContainerJsonAbsolutePath($root, $options['universePath'] ?? $configuredPath);
    $report = (new DetachedContainerJsonAuditService())->audit($universePath);

    if ($options['format'] === 'json') {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    } else {
        auditDetachedContainerJsonPrintText($report);
    }

    return $report['findings'] === [] ? 0 : 1;
}

/**
 * @param array<int, string> $argv
 * @return array{universePath:?string,format:string,help:bool}
 */
function auditDetachedContainerJsonParseArguments(array $argv): array
{
    $options = ['universePath' => null, 'format' => 'text', 'help' => false];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if (str_starts_with($argument, '--universe-path=')) {
            $value = trim(substr($argument, strlen('--universe-path=')));
            if ($value === '') {
                throw new InvalidArgumentException('Missing value for --universe-path.');
            }
            $options['universePath'] = $value;
            continue;
        }
        if (str_starts_with($argument, '--format=')) {
            $format = strtolower(trim(substr($argument, strlen('--format='))));
            if (!in_array($format, ['text', 'json'], true)) {
                throw new InvalidArgumentException('--format must be text or json.');
            }
            $options['format'] = $format;
            continue;
        }
        throw new InvalidArgumentException("Unknown argument: {$argument}");
    }

    return $options;
}

function auditDetachedContainerJsonUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/audit-detached-container-json.php [--universe-path=<path>] [--format=text|json]

Read-only audit of detached containers stored in sector JSON files. Detects
duplicate ids, identifier collisions, invalid JSON and incomplete snapshots.

Exit codes:
  0  Audit completed without findings
  1  Audit completed and found anomalies
  2  Invalid arguments or audit failure

TEXT;
}

function auditDetachedContainerJsonAbsolutePath(string $root, string $path): string
{
    return str_starts_with($path, DIRECTORY_SEPARATOR)
        ? $path
        : rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}

/**
 * @param array{
 *   universePath:string,
 *   filesScanned:int,
 *   containersScanned:int,
 *   findings:list<array{code:string,message:string,file:string,collection:?string,index:?int,containerId:?string}>,
 *   summary:array<string,int>
 * } $report
 */
function auditDetachedContainerJsonPrintText(array $report): void
{
    echo "Detached container JSON audit\n";
    echo "- universe: {$report['universePath']}\n";
    echo "- sector files scanned: {$report['filesScanned']}\n";
    echo "- detached containers scanned: {$report['containersScanned']}\n";
    echo "- findings: " . count($report['findings']) . "\n";

    foreach ($report['findings'] as $finding) {
        $location = $finding['file'] !== '' ? $finding['file'] : '(cross-file)';
        if ($finding['collection'] !== null) {
            $location .= ':' . $finding['collection'];
        }
        if ($finding['index'] !== null) {
            $location .= '[' . $finding['index'] . ']';
        }
        $id = $finding['containerId'] !== null ? " id={$finding['containerId']}" : '';
        echo "[{$finding['code']}] {$location}{$id}: {$finding['message']}\n";
    }
}
