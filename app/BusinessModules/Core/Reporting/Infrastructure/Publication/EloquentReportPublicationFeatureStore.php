<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Publication;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationFeatureConfiguration;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
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
        $row = $this->connection->table('public.report_publication_features')->where('code', $code)->first();

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

        try {
            $result = $this->connection->selectOne(<<<'SQL'
                SELECT public.report_publication_configure_feature(
                    ?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS jsonb)
                ) AS configured_at
                SQL, [
                $publication->code,
                $publication->publicationId,
                $publication->proofHash->value,
                $configuration->mode->value,
                CanonicalJson::encode($configuration->organizationAllowlist),
                CanonicalJson::encode($configuration->userAllowlist),
            ]);
            if ($result === null) {
                throw new LogicException('report_publication_feature_stale_identity');
            }
        } catch (QueryException $exception) {
            if ($this->hasSqlState($exception, 'P0002')) {
                throw new LogicException('report_publication_feature_stale_identity', 0, $exception);
            }

            throw $exception;
        }

        return $configuration;
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

    private function hasSqlState(QueryException $exception, string $sqlState): bool
    {
        return ($exception->errorInfo[0] ?? null) === $sqlState
            || (string) $exception->getCode() === $sqlState;
    }
}
