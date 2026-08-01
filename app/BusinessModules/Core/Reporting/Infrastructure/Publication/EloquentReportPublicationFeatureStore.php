<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Publication;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationFeatureConfiguration;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class EloquentReportPublicationFeatureStore implements ReportPublicationFeatureStore
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function current(string $code): ?ReportPublicationFeatureConfiguration
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1) {
            throw new InvalidArgumentException('report_publication_feature_code_invalid');
        }
        $row = $this->connection->table('report_publication_features')->where('code', $code)->first();

        return $row === null ? null : $this->hydrate((array) $row);
    }

    public function configure(
        ReportPublicationIdentity $publication,
        ReportPublicationFeatureMode $mode,
        array $organizationAllowlist,
        array $userAllowlist,
    ): ReportPublicationFeatureConfiguration {
        if ($mode === ReportPublicationFeatureMode::DISABLED) {
            throw new InvalidArgumentException('report_publication_disable_requires_rollback');
        }
        $configuration = new ReportPublicationFeatureConfiguration(
            $publication->code,
            $publication->publicationId,
            $publication->proofHash,
            $mode,
            $organizationAllowlist,
            $userAllowlist,
        );

        return $this->connection->transaction(function () use ($configuration, $publication): ReportPublicationFeatureConfiguration {
            $active = $this->connection->table('report_publications')
                ->where('id', $publication->publicationId)
                ->where('code', $publication->code)
                ->where('proof_sha256', $publication->proofHash->value)
                ->where('status', 'published')
                ->lockForUpdate()
                ->first();
            if ($active === null) {
                throw new LogicException('report_publication_feature_stale_identity');
            }
            $updatedAt = new DateTimeImmutable('now');
            $updated = $this->connection->table('report_publication_features')
                ->where('code', $publication->code)
                ->where('publication_id', $publication->publicationId)
                ->where('proof_sha256', $publication->proofHash->value)
                ->update([
                    'mode' => $configuration->mode->value,
                    'canary_organization_ids' => CanonicalJson::encode($configuration->organizationAllowlist),
                    'canary_user_ids' => CanonicalJson::encode($configuration->userAllowlist),
                    'updated_at' => $updatedAt,
                ]);
            if ($updated !== 1) {
                throw new LogicException('report_publication_feature_stale_identity');
            }

            $outboxId = (string) Str::ulid();
            $this->connection->table('report_publication_outbox')->insert([
                'id' => $outboxId,
                'publication_id' => $publication->publicationId,
                'event_type' => 'report_feature_configured',
                'deduplication_key' => $publication->publicationId.':report_feature_configured:'.$outboxId,
                'payload_json' => CanonicalJson::encode([
                    'canary_organization_ids' => $configuration->organizationAllowlist,
                    'canary_user_ids' => $configuration->userAllowlist,
                    'mode' => $configuration->mode->value,
                    'proof_sha256' => $publication->proofHash->value,
                    'publication_id' => $publication->publicationId,
                ]),
                'created_at' => $updatedAt,
                'delivered_at' => null,
            ]);

            return $configuration;
        });
    }

    private function hydrate(array $row): ReportPublicationFeatureConfiguration
    {
        return new ReportPublicationFeatureConfiguration(
            (string) $row['code'],
            (string) $row['publication_id'],
            new Sha256Hash((string) $row['proof_sha256']),
            ReportPublicationFeatureMode::from((string) $row['mode']),
            $this->ids($row['canary_organization_ids'] ?? null),
            $this->ids($row['canary_user_ids'] ?? null),
        );
    }

    private function ids(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new LogicException('report_publication_feature_persisted_ids_invalid');
        }

        return $decoded;
    }
}
