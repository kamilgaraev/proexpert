<?php

declare(strict_types=1);

namespace App\Console\Commands\LegalArchive;

use App\Services\LegalArchive\Integrations\LegalDocumentReconciliationService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ReconcileLegalDocumentSourcesCommand extends Command
{
    protected $signature = 'legal-archive:reconcile {--organization=} {--source=} {--dry-run} {--limit=100}';
    protected $description = 'Reconcile legal archive source links without inventing legal statuses';
    public function handle(LegalDocumentReconciliationService $service): int
    {
        try {
            $organization = $this->positiveInteger($this->option('organization'));
            $limit = $this->positiveInteger($this->option('limit')) ?? 100;
            $summary = $service->reconcile($organization, $this->option('source'), min($limit, 1000), (bool) $this->option('dry-run'));
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        } catch (\JsonException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
    private function positiveInteger(mixed $value): ?int
    {
        if ($value === null) return null;
        $value = is_scalar($value) ? (string) $value : '';
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) throw new InvalidArgumentException('Option must be a positive integer.');
        return (int) $value;
    }
}
