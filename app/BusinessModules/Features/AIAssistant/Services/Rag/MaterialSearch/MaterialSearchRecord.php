<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch;

use InvalidArgumentException;

final readonly class MaterialSearchRecord
{
    public string $ref;
    private array $fieldRefs;

    public function __construct(
        public string $title,
        public array $aliases,
        public string $rawUnit,
        public ?string $decimal,
        public ?string $currency,
        public ?string $priceBasisUnit,
        public ?string $vat,
        public string $generationRef,
    ) {
        if (!mb_check_encoding($title, 'UTF-8') || strlen($title) > 512 || trim($title) === ''
            || count($aliases) > 16 || count($aliases) === 0
            || !mb_check_encoding($rawUnit, 'UTF-8') || strlen($rawUnit) > 64
            || preg_match('~^ref_[a-f0-9]{32}$~D', $generationRef) !== 1
            || ($decimal !== null && preg_match('~^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,6})?$~D', $decimal) !== 1)
            || ($currency !== null && !in_array($currency, ['RUB'], true))
            || ($vat !== null && !in_array($vat, ['0%', '10%', '20%', '22%', 'exempt'], true))) {
            throw new InvalidArgumentException('invalid_material_search_record');
        }

        foreach ($aliases as $alias) {
            if (!is_string($alias) || !mb_check_encoding($alias, 'UTF-8') || strlen($alias) > 128 || trim($alias) === '') {
                throw new InvalidArgumentException('invalid_material_search_alias');
            }
        }

        $this->ref = self::opaqueRef();
        $refs = [];

        foreach (['title', 'unit', 'price', 'vat', 'price_basis'] as $field) {
            $refs[$field] = ['evidence' => self::opaqueRef(), 'fragment' => self::opaqueRef()];
        }

        $this->fieldRefs = $refs;
    }

    public static function opaqueRef(): string
    {
        return 'ref_' . bin2hex(random_bytes(16));
    }

    public function unit(): ?string
    {
        return MaterialSearchQuery::normalizeUnit($this->rawUnit);
    }

    public function score(MaterialSearchQuery $query): int
    {
        $requestedUnit = $query->requestedUnit();

        if ($requestedUnit !== null && $this->unit() !== $requestedUnit) {
            return 0;
        }

        $tokens = MaterialSearchQuery::tokens($query->text);
        $declared = MaterialSearchQuery::tokens(implode(' ', $this->aliases));

        if ($tokens === [] || array_diff($tokens, $declared) !== []) {
            return 0;
        }

        $titleTokens = MaterialSearchQuery::tokens($this->title);
        $score = count(array_intersect($tokens, $titleTokens)) * 10 + count($tokens) * 5;

        foreach ($this->aliases as $alias) {
            if ($tokens === MaterialSearchQuery::tokens($alias)) {
                $score += 20;
                break;
            }
        }

        return $score + ($requestedUnit !== null ? 10 : 0);
    }

    public function hasAuthoritativePrice(): bool
    {
        return $this->decimal !== null && $this->currency === 'RUB' && $this->unit() !== null
            && $this->priceBasisUnit !== null
            && MaterialSearchQuery::normalizeUnit($this->priceBasisUnit) === $this->unit();
    }

    public function facts(bool $financeAllowed): array
    {
        $facts = [$this->textFact($this->title, 'title')];
        $unit = $this->unit();

        if ($unit !== null) {
            $facts[] = $this->textFact($unit, 'unit');
        }

        if ($financeAllowed && $this->hasAuthoritativePrice()) {
            $price = ['decimal' => $this->decimal, 'currency' => 'RUB', 'perUnit' => $unit, 'period' => null];
            $facts[] = ['kind' => 'price', ...$price, 'provenance' => $this->provenance('price', $price)];
            $facts[] = $this->textFact((string) $this->priceBasisUnit, 'price_basis');

            if ($this->vat !== null) {
                $facts[] = $this->textFact($this->vat, 'vat');
            }
        }

        return $facts;
    }

    private function textFact(string $value, string $field): array
    {
        return ['kind' => 'text', 'value' => ['utf8Text' => $value], 'provenance' => $this->provenance($field, $value)];
    }

    private function provenance(string $field, array|string $value): array
    {
        return [
            'schemaVersion' => 'safe-provenance/1',
            'evidenceRef' => $this->fieldRefs[$field]['evidence'],
            'sourceKind' => 'entity',
            'sourceGenerationRef' => $this->generationRef,
            'unitRef' => $this->ref,
            'pageRef' => null,
            'fragmentRef' => $this->fieldRefs[$field]['fragment'],
            'fragmentVersion' => 'synthetic-material-field/' . $field . '/1',
            'contentDigest' => hash('sha256', is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR)),
            'validationVersion' => 'most-ai-material-evidence/1',
        ];
    }
}
