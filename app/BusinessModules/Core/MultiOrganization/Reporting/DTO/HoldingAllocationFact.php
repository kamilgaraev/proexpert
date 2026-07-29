<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\DTO;

use InvalidArgumentException;

final readonly class HoldingAllocationFact
{
    public function __construct(
        public int $organizationId,
        public int $holdingId,
        public int $contributorOrganizationId,
        public ?int $counterpartyOrganizationId,
        public int $projectId,
        public int $contractId,
        public int $allocationId,
        public ?int $linkedParentAllocationId,
        public string $sourceType,
        public int $sourceId,
        public int $sourceVersion,
        public string $monetaryBasis,
        public int $amountMinor,
        public ?string $currency,
        public string $currencySource,
        public string $recognizedOn,
        public string $flowClass,
        public array $sourceRefs = [],
    ) {
        if (min($organizationId, $holdingId, $contributorOrganizationId, $projectId, $contractId, $allocationId, $sourceId, $sourceVersion) < 1
            || ($counterpartyOrganizationId !== null && $counterpartyOrganizationId < 1)
            || ($linkedParentAllocationId !== null && $linkedParentAllocationId < 1)
            || !in_array($monetaryBasis, ['contracted', 'accepted_accrual', 'cash'], true)
            || ($currency !== null && preg_match('/^[A-Z]{3}$/D', $currency) !== 1)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $currencySource) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $recognizedOn) !== 1
            || !in_array($flowClass, ['internal', 'external', 'unclassified'], true)
            || !array_is_list($sourceRefs)) {
            throw new InvalidArgumentException('holding_allocation_fact_invalid');
        }
    }

    public static function contracted(
        int $organizationId,
        int $contributorOrganizationId,
        int $projectId,
        ?string $currency,
        int $amountMinor,
    ): self {
        return self::fixture($organizationId, $contributorOrganizationId, $projectId, $currency, $amountMinor, 'contracted');
    }

    public static function acceptedAccrual(
        int $organizationId,
        int $contributorOrganizationId,
        int $projectId,
        ?string $currency,
        int $amountMinor,
    ): self {
        return self::fixture($organizationId, $contributorOrganizationId, $projectId, $currency, $amountMinor, 'accepted_accrual');
    }

    public static function cash(
        int $organizationId,
        int $contributorOrganizationId,
        int $projectId,
        ?string $currency,
        int $amountMinor,
    ): self {
        return self::fixture($organizationId, $contributorOrganizationId, $projectId, $currency, $amountMinor, 'cash');
    }

    public function sourceKey(): string
    {
        return implode(':', [
            $this->organizationId,
            $this->sourceType,
            $this->sourceId,
            $this->sourceVersion,
            $this->monetaryBasis,
        ]);
    }

    private static function fixture(
        int $organizationId,
        int $contributorOrganizationId,
        int $projectId,
        ?string $currency,
        int $amountMinor,
        string $basis,
    ): self {
        $sourceId = ($contributorOrganizationId * 1000) + $projectId;

        return new self(
            $organizationId,
            $organizationId,
            $contributorOrganizationId,
            null,
            $projectId,
            $sourceId,
            $sourceId,
            null,
            $basis === 'contracted' ? 'contract' : ($basis === 'cash' ? 'transaction' : 'act'),
            $sourceId,
            1,
            $basis,
            $amountMinor,
            $currency,
            $currency === null ? 'unknown' : 'source',
            '2026-07-29',
            'unclassified',
        );
    }
}
