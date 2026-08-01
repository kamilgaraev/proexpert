<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewVersionStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSavedViewVersionData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewVersion;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewVersionContent;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSavedViewVersionRecord;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use TypeError;
use ValueError;

final class EloquentReportSavedViewVersionStore implements ReportSavedViewVersionStore
{
    public function append(CreateReportSavedViewVersionData $data): ReportSavedViewVersion
    {
        $id = (string) Str::ulid();
        $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        ReportSavedViewVersionRecord::query()->insert([
            'id' => $id,
            'saved_view_id' => $data->savedViewId,
            'organization_id' => $data->organizationId,
            'owner_id' => $data->ownerId,
            'revision' => $data->revision,
            'report_code' => $data->content->reportCode,
            'contract_version' => $data->content->contractVersion,
            'content_json' => $data->content->canonicalBytes(),
            'content_hash' => $data->contentHash->value,
            'report_definition_hash' => $data->reportDefinitionHash->value,
            'created_at' => $createdAt,
        ]);

        return new ReportSavedViewVersion(
            $id,
            $data->savedViewId,
            $data->organizationId,
            $data->ownerId,
            $data->revision,
            $data->content,
            $data->contentHash,
            $data->reportDefinitionHash,
            $createdAt,
        );
    }

    public function find(string $savedViewId, int $revision): ?ReportSavedViewVersion
    {
        $record = ReportSavedViewVersionRecord::query()
            ->where('saved_view_id', $savedViewId)
            ->where('revision', $revision)
            ->first();

        return $record instanceof ReportSavedViewVersionRecord
            ? $this->hydrate($record)
            : null;
    }

    private function hydrate(ReportSavedViewVersionRecord $record): ReportSavedViewVersion
    {
        try {
            if (! is_array($record->content_json)
                || ! $record->created_at instanceof DateTimeInterface) {
                throw new InvalidArgumentException('report_saved_view_version_persistence_invalid');
            }

            $content = ReportSavedViewVersionContent::fromArray($record->content_json);
            if (! hash_equals($content->reportCode, (string) $record->report_code)
                || ! hash_equals($content->contractVersion, (string) $record->contract_version)) {
                throw new InvalidArgumentException('report_saved_view_version_binding_mismatch');
            }

            return new ReportSavedViewVersion(
                (string) $record->id,
                (string) $record->saved_view_id,
                (int) $record->organization_id,
                (int) $record->owner_id,
                (int) $record->revision,
                $content,
                new Sha256Hash((string) $record->content_hash),
                new Sha256Hash((string) $record->report_definition_hash),
                DateTimeImmutable::createFromInterface($record->created_at),
            );
        } catch (InvalidArgumentException|TypeError|ValueError $exception) {
            throw new LogicException('report_saved_view_version_persistence_invalid', 0, $exception);
        }
    }
}
