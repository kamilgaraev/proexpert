<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportPublicationFeatureConfiguration
{
    public array $organizationAllowlist;

    public array $userAllowlist;

    public function __construct(
        public string $code,
        public string $publicationId,
        public Sha256Hash $proofHash,
        public ReportPublicationFeatureMode $mode,
        array $organizationAllowlist,
        array $userAllowlist,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $publicationId) !== 1) {
            throw new InvalidArgumentException('report_publication_feature_configuration_invalid');
        }
        $this->organizationAllowlist = self::identities($organizationAllowlist);
        $this->userAllowlist = self::identities($userAllowlist);
        $hasAllowlist = $this->organizationAllowlist !== [] || $this->userAllowlist !== [];
        if (($mode === ReportPublicationFeatureMode::CANARY) !== $hasAllowlist) {
            throw new InvalidArgumentException('report_publication_feature_configuration_invalid');
        }
    }

    private static function identities(array $ids): array
    {
        if (! array_is_list($ids)) {
            throw new InvalidArgumentException('report_publication_feature_configuration_invalid');
        }
        $seen = [];
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1 || isset($seen[$id])) {
                throw new InvalidArgumentException('report_publication_feature_configuration_invalid');
            }
            $seen[$id] = true;
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
