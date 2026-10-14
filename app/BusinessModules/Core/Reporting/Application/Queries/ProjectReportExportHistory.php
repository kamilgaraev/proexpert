<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Queries;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

final readonly class ProjectReportExportHistory
{
    public function __construct(
        private ConnectionInterface $database,
        private ReportHttpAuthorizationOrchestrator $authorization,
        private GetReportExportAction $exports,
        private ReportCatalogMetadataRegistry $metadata,
    ) {}

    public function read(int $actorId, int $organizationId, int $projectId, ?string $cursor = null): array
    {
        if ($actorId < 1 || $organizationId < 1 || $projectId < 1) {
            throw new InvalidArgumentException('report_history_scope_invalid');
        }
        $before = null;
        if ($cursor !== null) {
            try {
                $decoded = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
                if ($decoded['scope'] !== [$actorId, $organizationId, $projectId]
                    || ! is_string($decoded['before']) || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $decoded['before']) !== 1) {
                    throw new InvalidArgumentException('report_history_cursor_invalid');
                }
                $before = $decoded['before'];
            } catch (\Throwable $exception) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID, ['fields' => ['cursor']], $exception);
            }
        }

        $read = function () use ($actorId, $organizationId, $projectId, $before): array {
            $query = ReportExportRecord::query()->where('organization_id', $organizationId)
                ->whereJsonContains('scope_project_ids', $projectId)->orderByDesc('id');
            if ($before !== null) {
                $query->where('id', '<', $before);
            }
            $candidates = $query->limit(101)->pluck('id')->all();
            $more = count($candidates) > 100;
            $candidates = array_slice($candidates, 0, 100);
            $items = [];
            foreach ($candidates as $id) {
                try {
                    $authorized = $this->authorization->exportForActor($actorId, $organizationId, $id);
                    if (! in_array($projectId, $authorized['context']->scope->projectIds, true)) {
                        continue;
                    }
                    $export = $this->exports->handle($authorized['context'], $id);
                    $items[] = [
                        'id' => $export->id, 'run_id' => $export->runId,
                        'report_code' => $authorized['authorization']->target->definition->code,
                        'title' => trans_message($this->metadata->published($authorized['authorization']->target->definition->code)->titleKey),
                        'status' => $export->status->value, 'format' => $export->format,
                        'created_at' => $export->createdAt->format(DATE_ATOM),
                        'ready_at' => $export->readyAt?->format(DATE_ATOM),
                        'expires_at' => $export->expiresAt->format(DATE_ATOM),
                        'retention_expired' => $export->expiresAt <= now()->toDateTimeImmutable(),
                    ];
                } catch (ReportContractException $exception) {
                    if (! in_array($exception->errorCode, [ReportErrorCode::REPORT_NOT_FOUND, ReportErrorCode::REPORT_SCOPE_FORBIDDEN], true)) {
                        throw $exception;
                    }
                }
            }

            return ['items' => $items, 'complete' => ! $more,
                'next_cursor' => $more ? Crypt::encryptString(json_encode(['scope' => [$actorId, $organizationId, $projectId], 'before' => end($candidates)], JSON_THROW_ON_ERROR)) : null];
        };

        if ($this->database->transactionLevel() > 0) {
            return $read();
        }

        return $this->database->transaction(function () use ($read): array {
            $this->database->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            return $read();
        });
    }
}
