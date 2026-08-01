<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Core\Reporting\Application\Publication\ProductionReportPublicationReleaseIngestion;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class IngestReportPublicationReleaseCommand extends Command
{
    protected $signature = 'reports:publications:ingest-release
        {--artifact-name= : Имя подписанного артефакта без расширения}
        {--mode=off : Режим доступности отчёта}
        {--organization-id=* : Идентификатор организации для ограниченного режима}
        {--user-id=* : Идентификатор пользователя для ограниченного режима}';

    protected $description = 'Принимает подписанный выпуск отчёта из доверенного CI-артефакта';

    public function handle(ProductionReportPublicationReleaseIngestion $ingestion): int
    {
        try {
            $mode = ReportPublicationFeatureMode::tryFrom((string) $this->option('mode'));
            if ($mode === null) {
                throw new InvalidArgumentException('report_publication_release_input_invalid');
            }
            $published = $ingestion->ingest(
                $this->requiredOption('artifact-name'),
                $mode,
                $this->integerOptions('organization-id'),
                $this->integerOptions('user-id'),
            );
            $this->line($published->code);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('report_publication_release_input_invalid');
        }

        return $value;
    }

    /** @return int[] */
    private function integerOptions(string $name): array
    {
        $values = $this->option($name);
        if (! is_array($values)) {
            throw new InvalidArgumentException('report_publication_release_input_invalid');
        }
        $identifiers = [];
        foreach ($values as $value) {
            if (! is_string($value)
                || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
                || isset($identifiers[(int) $value])) {
                throw new InvalidArgumentException('report_publication_release_input_invalid');
            }
            $identifiers[(int) $value] = true;
        }

        return array_keys($identifiers);
    }
}
