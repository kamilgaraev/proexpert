<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\DTOs\IncomingUpdValidationResult;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DOMDocument;
use DOMElement;

final class IncomingUpdXmlValidator
{
    public const FORMAT_VERSION = '5.03';

    public const MAX_BYTES = 10 * 1024 * 1024;

    private const FUNCTIONS = ['ДОП', 'СЧФДОП'];

    private readonly string $schemaPath;

    public function __construct(?string $schemaPath = null)
    {
        $this->schemaPath = $schemaPath ?? dirname(__DIR__, 5)
            .'/resources/schemas/fns/upd-5.03/ON_NSCHFDOPPR_1_997_01_05_03_05.xsd';
    }

    public function validate(string $contents, string $sourceName): IncomingUpdValidationResult
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            return $this->failed('xml_invalid');
        }

        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $contents) === 1) {
            return $this->failed('unsafe_xml');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if (! $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                return $this->failed('xml_invalid');
            }

            $errors = [];

            if (! is_file($this->schemaPath) || ! $document->schemaValidate($this->schemaPath)) {
                $errors[] = ['code' => 'schema_invalid'];
            }

            $file = $document->documentElement;
            if (! $file instanceof DOMElement || $file->tagName !== 'Файл') {
                return $this->failed('xml_invalid');
            }

            $fileId = $this->attribute($file, 'ИдФайл');
            if ($fileId !== $this->sourceIdentifier($sourceName)) {
                $errors[] = ['code' => 'filename_mismatch'];
            }

            $formatVersion = $this->attribute($file, 'ВерсФорм');
            if ($formatVersion !== self::FORMAT_VERSION) {
                $errors[] = ['code' => 'format_version_unsupported'];
            }

            $documentNode = $this->firstDescendant($file, 'Документ');
            $function = $this->attribute($documentNode, 'Функция');
            if (! in_array($function, self::FUNCTIONS, true)) {
                $errors[] = ['code' => 'function_unsupported'];
            }
            if (
                in_array($function, self::FUNCTIONS, true)
                && $this->firstDescendant($documentNode, 'СвПродПер') === null
            ) {
                $errors[] = ['code' => 'transfer_details_missing'];
            }
            if (
                $function === 'СЧФДОП'
                && $this->firstDescendant($documentNode, 'ТаблСчФакт') === null
            ) {
                $errors[] = ['code' => 'items_table_missing'];
            }

            $invoice = $this->firstDescendant($documentNode, 'СвСчФакт');
            $currency = $this->firstDescendant($invoice, 'ДенИзм');
            $seller = $this->party($this->firstDescendant($invoice, 'СвПрод'));
            $buyer = $this->party($this->firstDescendant($invoice, 'СвПокуп'));
            $items = $this->items($documentNode);
            $totals = $this->totals($documentNode);

            if ($items === []) {
                $errors[] = ['code' => 'items_missing'];
            }
            array_push($errors, ...$this->amountErrors($items, $totals));

            return new IncomingUpdValidationResult(
                fileId: $fileId,
                formatVersion: $formatVersion,
                function: $function,
                number: $this->attribute($invoice, 'НомерДок'),
                date: $this->attribute($invoice, 'ДатаДок'),
                currencyCode: $this->attribute($currency, 'КодОКВ'),
                seller: $seller,
                buyer: $buyer,
                items: $items,
                totals: $totals,
                errors: $this->uniqueIssues($errors),
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<string, string|null> */
    private function party(?DOMElement $party): array
    {
        $identity = $this->firstDescendant($party, 'ИдСв');
        $node = $identity?->firstElementChild;
        if (! $node instanceof DOMElement) {
            return ['name' => null, 'inn' => null, 'kpp' => null];
        }

        return match ($node->tagName) {
            'СвЮЛУч' => [
                'name' => $this->attribute($node, 'НаимОрг'),
                'inn' => $this->attribute($node, 'ИННЮЛ'),
                'kpp' => $this->attribute($node, 'КПП'),
            ],
            'СвИП' => [
                'name' => $this->personName($this->firstDescendant($node, 'ФИО')),
                'inn' => $this->attribute($node, 'ИННФЛ'),
                'kpp' => null,
            ],
            default => ['name' => null, 'inn' => null, 'kpp' => null],
        };
    }

    /** @return array<int, array<string, string|null>> */
    private function items(?DOMElement $document): array
    {
        $items = [];
        if (! $document instanceof DOMElement) {
            return [];
        }

        $nodes = $document->getElementsByTagName('СведТов');
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $taxContainer = $this->firstDescendant($node, 'СумНал');
            $tax = $taxContainer?->firstElementChild;
            $items[] = [
                'line_number' => $this->attribute($node, 'НомСтр'),
                'name' => $this->attribute($node, 'НаимТов'),
                'unit_code' => $this->attribute($node, 'ОКЕИ_Тов'),
                'unit_name' => $this->attribute($node, 'НаимЕдИзм'),
                'quantity' => $this->attribute($node, 'КолТов'),
                'price' => $this->attribute($node, 'ЦенаТов'),
                'without_vat' => $this->attribute($node, 'СтТовБезНДС'),
                'vat_rate' => $this->attribute($node, 'НалСт'),
                'vat_amount' => $tax?->tagName === 'СумНал' ? trim($tax->textContent) : '0',
                'with_vat' => $this->attribute($node, 'СтТовУчНал'),
            ];
        }

        return $items;
    }

    /** @return array<string, string|null> */
    private function totals(?DOMElement $document): array
    {
        $node = $this->firstDescendant($document, 'ВсегоОпл');
        $taxContainer = $this->firstDescendant($node, 'СумНалВсего');
        $tax = $taxContainer?->firstElementChild;

        return [
            'without_vat' => $this->attribute($node, 'СтТовБезНДСВсего'),
            'vat_amount' => $tax?->tagName === 'СумНал' ? trim($tax->textContent) : '0',
            'with_vat' => $this->attribute($node, 'СтТовУчНалВсего'),
        ];
    }

    private function personName(?DOMElement $node): ?string
    {
        if (! $node instanceof DOMElement) {
            return null;
        }

        $parts = array_filter([
            $this->attribute($node, 'Фамилия'),
            $this->attribute($node, 'Имя'),
            $this->attribute($node, 'Отчество'),
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function firstDescendant(?DOMElement $context, string $name): ?DOMElement
    {
        if (! $context instanceof DOMElement) {
            return null;
        }

        $node = $context->getElementsByTagName($name)->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function attribute(?DOMElement $node, string $name): ?string
    {
        if (! $node instanceof DOMElement || ! $node->hasAttribute($name)) {
            return null;
        }

        $value = trim($node->getAttribute($name));

        return $value === '' ? null : $value;
    }

    private function sourceIdentifier(string $sourceName): string
    {
        $name = basename(str_replace('\\', '/', trim($sourceName)));

        return preg_replace('/\.xml$/i', '', $name) ?? $name;
    }

    /**
     * @param  array<int, array<string, string|null>>  $items
     * @param  array<string, string|null>  $totals
     * @return array<int, array{code: string, line_number?: string|null}>
     */
    private function amountErrors(array $items, array $totals): array
    {
        if ($items === []) {
            return [];
        }

        $issues = [];
        $withoutVatTotal = BigDecimal::zero();
        $vatTotal = BigDecimal::zero();
        $withVatTotal = BigDecimal::zero();

        try {
            foreach ($items as $item) {
                $quantity = BigDecimal::of((string) ($item['quantity'] ?? ''));
                $price = BigDecimal::of((string) ($item['price'] ?? ''));
                $withoutVat = BigDecimal::of((string) ($item['without_vat'] ?? ''));
                $vat = BigDecimal::of((string) ($item['vat_amount'] ?? ''));
                $withVat = BigDecimal::of((string) ($item['with_vat'] ?? ''));
                $lineNumber = $item['line_number'] ?? null;

                if (! $this->sameMoney($quantity->multipliedBy($price), $withoutVat)) {
                    $issues[] = ['code' => 'line_amount_mismatch', 'line_number' => $lineNumber];
                }
                if (! $this->sameMoney($withoutVat->plus($vat), $withVat)) {
                    $issues[] = ['code' => 'line_tax_total_mismatch', 'line_number' => $lineNumber];
                }

                $withoutVatTotal = $withoutVatTotal->plus($withoutVat);
                $vatTotal = $vatTotal->plus($vat);
                $withVatTotal = $withVatTotal->plus($withVat);
            }

            if (
                ! $this->sameMoney($withoutVatTotal, BigDecimal::of((string) ($totals['without_vat'] ?? '')))
                || ! $this->sameMoney($vatTotal, BigDecimal::of((string) ($totals['vat_amount'] ?? '')))
                || ! $this->sameMoney($withVatTotal, BigDecimal::of((string) ($totals['with_vat'] ?? '')))
            ) {
                $issues[] = ['code' => 'document_totals_mismatch'];
            }
        } catch (\Throwable) {
            $issues[] = ['code' => 'amount_invalid'];
        }

        return $issues;
    }

    private function sameMoney(BigDecimal $first, BigDecimal $second): bool
    {
        return $first->toScale(2, RoundingMode::HALF_UP)
            ->isEqualTo($second->toScale(2, RoundingMode::HALF_UP));
    }

    /**
     * @param  array<int, array{code: string}>  $issues
     * @return array<int, array{code: string}>
     */
    private function uniqueIssues(array $issues): array
    {
        $unique = [];

        foreach ($issues as $issue) {
            $unique[$issue['code'].'|'.($issue['line_number'] ?? '')] = $issue;
        }

        return array_values($unique);
    }

    private function failed(string $code): IncomingUpdValidationResult
    {
        return new IncomingUpdValidationResult(errors: [['code' => $code]]);
    }
}
