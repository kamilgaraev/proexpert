<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch;

use App\Services\Privacy\Contracts\AuthenticatedPrivateContext;
use InvalidArgumentException;

final class SyntheticMaterialSearchCorpus implements MaterialSearchCorpus
{
    private AuthenticatedPrivateContext $principal;
    private array $rows = [];
    private array $deniedRefs = [];
    private string $scopeRef;
    private string $generationRef;
    private string $profileRef;
    private bool $scopeAllowed = true;
    private bool $financeAllowed = true;
    private bool $sourceFresh = true;
    private int $checks = 0;
    private ?int $scheduledCheck = null;
    private ?string $scheduledAction = null;

    private function __construct(string $fixture)
    {
        $this->principal = new AuthenticatedPrivateContext(7, 11, 13,
            'most-ai-v1-purpose-policy/0.2-product-approved-20261005', 'material-search-fixture-acl/1');
        $this->scopeRef = MaterialSearchRecord::opaqueRef();
        $this->generationRef = MaterialSearchRecord::opaqueRef();
        $this->profileRef = MaterialSearchRecord::opaqueRef();
        $definitions = [
            ['Бетон товарный В25 М350', ['бетон', 'товарный бетон', 'готовая бетонная смесь', 'в25', 'м350'], 'м³', '7800.00', 'RUB', 'm3', '20%'],
            ['Бетон товарный В30 М400', ['бетон', 'товарный бетон', 'готовая бетонная смесь', 'в30', 'м400'], 'м3', '8250.50', 'RUB', 'm3', null],
            ['Смесь бетонная В15 М200', ['бетон', 'бетонная смесь', 'в15', 'м200'], 'куб. м', '6500.00', null, null, null],
            ['Гидроизоляция для бетона', ['гидроизоляция', 'покрытие', 'мастика'], 'м²', '350.00', 'RUB', 'm2', '20%'],
            ['Перемычка железобетонная', ['перемычка', 'изделие', 'железобетон'], 'шт.', '1900.00', 'RUB', 'item', '20%'],
            ['Цемент М500', ['цемент', 'м500'], 'кг', '16.40', 'RUB', 'kg', null],
        ];

        if ($fixture === 'material-search-unknown-unit-v1') {
            $definitions = [['Бетон с неподтверждённой единицей', ['бетон'], 'неизвестно', '7800.00', 'RUB', null, null]];
        }

        if ($fixture === 'material-search-mismatched-price-v1') {
            $definitions = [['Бетон с несовместимым основанием цены', ['бетон'], 'м³', '7800.00', 'RUB', 'kg', null]];
        }

        if ($fixture === 'material-search-zero-price-v1') {
            $definitions = [['Цемент с подтверждённой нулевой ценой', ['цемент'], 'кг', '0.00', 'RUB', 'kg', null]];
        }

        foreach ($definitions as [$title, $aliases, $unit, $decimal, $currency, $basis, $vat]) {
            $row = new MaterialSearchRecord($title, $aliases, $unit, $decimal, $currency, $basis, $vat, $this->generationRef);
            $this->rows[$row->ref] = $row;
        }
    }

    public static function named(string $fixture): self
    {
        if (!in_array($fixture, ['material-search-v1', 'material-search-unknown-unit-v1',
            'material-search-mismatched-price-v1', 'material-search-zero-price-v1'], true)) {
            throw new InvalidArgumentException('unknown_material_search_fixture');
        }

        return new self($fixture);
    }

    public function context(): AuthenticatedPrivateContext
    {
        return $this->principal;
    }

    public function guard(AuthenticatedPrivateContext $context): ?string
    {
        $this->checks++;

        if ($this->scheduledCheck === $this->checks) {
            match ($this->scheduledAction) {
                'scope' => $this->revokeScope(),
                'price' => $this->revokePrice(),
                'source' => $this->invalidateSource(),
                default => null,
            };
        }

        if (!$context->sameSnapshot($this->principal) || !$this->scopeAllowed) {
            return 'access_denied';
        }

        return $this->sourceFresh ? null : 'source_stale';
    }

    public function records(): array
    {
        return array_values($this->rows);
    }

    public function recordAllowed(AuthenticatedPrivateContext $context, string $ref): bool
    {
        return $context->sameSnapshot($this->principal) && $this->scopeAllowed && $this->sourceFresh
            && isset($this->rows[$ref]) && !isset($this->deniedRefs[$ref]);
    }

    public function priceAllowed(AuthenticatedPrivateContext $context): bool
    {
        return $context->sameSnapshot($this->principal) && $this->scopeAllowed && $this->sourceFresh && $this->financeAllowed;
    }

    public function scope(string $kind, array $unitRefs): array
    {
        return ['kind' => $kind, 'scopeRef' => $this->scopeRef,
            'sourceGenerationRef' => $this->generationRef, 'unitRefs' => $unitRefs];
    }

    public function profileRef(): string
    {
        return $this->profileRef;
    }

    public function profileVersion(): string
    {
        return 'most-ai-local-material-search/1';
    }

    public function revokeScope(): void
    {
        $this->scopeAllowed = false;
    }

    public function revokePrice(): void
    {
        $this->financeAllowed = false;
    }

    public function revokeRecord(string $ref): void
    {
        $this->deniedRefs[$ref] = true;
    }

    public function invalidateSource(): void
    {
        $this->sourceFresh = false;
    }

    public function invalidateAtCheck(int $check, string $action): void
    {
        if ($check <= $this->checks || !in_array($action, ['scope', 'price', 'source'], true)) {
            throw new InvalidArgumentException('invalid_fixture_invalidation');
        }

        $this->scheduledCheck = $check;
        $this->scheduledAction = $action;
    }
}
