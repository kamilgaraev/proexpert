<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Credits\AICreditReadinessService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;

final class AICreditShadowReportCommand extends Command
{
    protected $signature = 'ai-credits:shadow-report {traces : Local JSON trace file} {--quality-only : Return quality status without paid approval} {--approve-ready : Sign eligible actual report for paid enforcement} {--approve-launch : Sign an eligible measured prelaunch report bound to assistant implementation and prices} {--output= : Private approval output path}';

    protected $description = 'Проверить фактические теневые трассы помощника без вызовов модели и обращения к БД';

    public function handle(AICreditReadinessService $readiness): int
    {
        if (count(array_filter([$this->option('quality-only'), $this->option('approve-ready'), $this->option('approve-launch')])) > 1) {
            $this->error('conflicting_readiness_approval_options');
            return self::INVALID;
        }
        try {
            $report = $readiness->reportFromFile((string) $this->argument('traces'));
        } catch (InvalidArgumentException|JsonException $exception) {
            $this->error($exception->getMessage());
            return self::INVALID;
        }
        if ($this->option('approve-ready') || $this->option('approve-launch')) {
            try {
                $approval = $this->option('approve-launch')
                    ? $readiness->approveLaunchReport($report, (string) config('app.key'), (string) config('ai-assistant-credits.release_sha'))
                    : $readiness->approveReport($report, (string) config('app.key'));
                $path = $this->option('output') ?: storage_path('app/private/assistant-credit-readiness.json');
                $directory = dirname($path);
                if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
                    $this->error('approval_directory_write_failed');
                    return self::FAILURE;
                }
                $temporaryPath = tempnam($directory, '.readiness-');
                if ($temporaryPath === false || file_put_contents($temporaryPath, json_encode($approval, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION), LOCK_EX) === false) {
                    $this->error('approval_file_write_failed');
                    return self::FAILURE;
                }
                chmod($temporaryPath, 0600);
                if (!rename($temporaryPath, $path)) {
                    unlink($temporaryPath);
                    $this->error('approval_file_replace_failed');
                    return self::FAILURE;
                }
            } catch (InvalidArgumentException $exception) {
                $this->error($exception->getMessage());
                return self::FAILURE;
            }
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));
        return ($this->option('quality-only') ? $report['quality_ready'] : $report['ready_for_approval']) ? self::SUCCESS : self::FAILURE;
    }
}
