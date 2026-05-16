<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Console\Commands;

use App\BusinessModules\Core\Mdm\Services\MdmDuplicateDetectionService;
use App\BusinessModules\Core\Mdm\Services\MdmRecordService;
use App\BusinessModules\Core\Mdm\Services\MdmRelationshipService;
use Illuminate\Console\Command;

class MdmSyncCommand extends Command
{
    protected $signature = 'mdm:sync {organization_id : ID организации} {--entity= : Тип справочника} {--duplicates : Проверить дубли} {--relationships : Обновить связи}';

    protected $description = 'Синхронизирует записи MDM с текущими справочниками организации';

    public function handle(
        MdmRecordService $recordService,
        MdmDuplicateDetectionService $duplicateDetectionService,
        MdmRelationshipService $relationshipService
    ): int {
        $organizationId = (int) $this->argument('organization_id');
        $entityType = $this->option('entity') ? (string) $this->option('entity') : null;

        $result = $recordService->syncOrganization($organizationId, $entityType);
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if ($this->option('duplicates')) {
            $this->line(json_encode(
                $duplicateDetectionService->scanOrganization($organizationId, $entityType),
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            ));
        }

        if ($this->option('relationships')) {
            $this->line(json_encode(
                $relationshipService->syncOrganization($organizationId),
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            ));
        }

        return self::SUCCESS;
    }
}
