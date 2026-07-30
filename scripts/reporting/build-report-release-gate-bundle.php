<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Opis\JsonSchema\CompliantValidator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $options = getopt('', ['input:', 'release-sha:', 'activation-commit:', 'admin-evidence-commit:', 'output:', 'check']);
    foreach (['input', 'release-sha', 'activation-commit', 'admin-evidence-commit', 'output'] as $name) {
        if (!isset($options[$name]) || !is_string($options[$name])) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
    }
    foreach (['release-sha', 'activation-commit', 'admin-evidence-commit'] as $name) {
        if (preg_match('/^[a-f0-9]{40}$/', $options[$name]) !== 1) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
    }
    $bytes = @file_get_contents($options['input']);
    $root = dirname(__DIR__, 2);
    $schemaBytes = @file_get_contents($root.'/docs/reports/contracts/report-release-gate-bundle.schema.json');
    if (!is_string($bytes) || !is_string($schemaBytes)) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::MISSING);
    }
    $document = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
    $schema = json_decode($schemaBytes, false, 512, JSON_THROW_ON_ERROR);
    if (!(new CompliantValidator())->validate($document, $schema)->isValid()
        || !is_object($document)
        || ($document->release_sha ?? null) !== $options['release-sha']
        || ($document->activation_commit_sha ?? null) !== $options['activation-commit']
        || ($document->admin_evidence_commit_sha ?? null) !== $options['admin-evidence-commit']) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
    }
    $canonical = CanonicalJson::encode(json_decode($bytes, true, 512, JSON_THROW_ON_ERROR))."\n";
    if (!hash_equals($canonical, $bytes)) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
    }
    if (isset($options['check'])) {
        if (@file_get_contents($options['output']) !== $bytes) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
    } else {
        $directory = dirname($options['output']);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
        file_put_contents($options['output'], $bytes);
    }
    fwrite(STDOUT, "report-release-gate-bundle: release_gates_passed 14/14\n");
} catch (ReportQualityGateException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit($exception->exitCode());
} catch (Throwable) {
    fwrite(STDERR, "quality-gate:invalid\n");
    exit(2);
}
