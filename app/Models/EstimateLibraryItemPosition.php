<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateLibraryItemPosition extends Model
{
    use HasFactory;

    protected $fillable = [
        'library_item_id',
        'normative_rate_id',
        'normative_rate_code',
        'name',
        'description',
        'measurement_unit',
        'sort_order',
        'quantity_formula',
        'default_quantity',
        'coefficient',
        'parameters_mapping',
        'metadata',
    ];

    protected $casts = [
        'default_quantity' => 'decimal:4',
        'coefficient' => 'decimal:4',
        'parameters_mapping' => 'array',
        'metadata' => 'array',
    ];

    public function libraryItem(): BelongsTo
    {
        return $this->belongsTo(EstimateLibraryItem::class, 'library_item_id');
    }

    public function normativeRate(): BelongsTo
    {
        return $this->belongsTo(NormativeRate::class, 'normative_rate_id');
    }

    public function scopeByLibraryItem($query, int $libraryItemId)
    {
        return $query->where('library_item_id', $libraryItemId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public function calculateQuantity(array $parameters = []): float
    {
        if (! $this->quantity_formula) {
            return (float) $this->default_quantity;
        }

        try {
            $result = $this->evaluateQuantityFormula((string) $this->quantity_formula, $parameters);

            if ($result === null) {
                return (float) $this->default_quantity;
            }

            return (float) ($result * (float) $this->coefficient);
        } catch (\Throwable $e) {
            return (float) $this->default_quantity;
        }
    }

    public function hasFormula(): bool
    {
        return ! empty($this->quantity_formula);
    }

    private function evaluateQuantityFormula(string $formula, array $parameters): ?float
    {
        $formula = $this->substituteQuantityFormulaParameters($formula, $parameters);

        if ($formula === null || trim($formula) === '' || preg_match('/[^0-9+\-*\/().\s]/', $formula)) {
            return null;
        }

        $offset = 0;
        $result = $this->parseFormulaExpression($formula, $offset);

        $this->skipFormulaWhitespace($formula, $offset);

        if ($result === null || $offset !== strlen($formula) || ! is_finite($result)) {
            return null;
        }

        return $result;
    }

    private function substituteQuantityFormulaParameters(string $formula, array $parameters): ?string
    {
        $valid = true;

        $resolved = preg_replace_callback(
            '/\{([^{}]+)\}/',
            static function (array $matches) use ($parameters, &$valid): string {
                $key = (string) $matches[1];

                if (! array_key_exists($key, $parameters) || ! is_numeric($parameters[$key])) {
                    $valid = false;

                    return '';
                }

                $value = (float) $parameters[$key];

                if (! is_finite($value)) {
                    $valid = false;

                    return '';
                }

                $formatted = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');

                return $formatted === '-0' ? '0' : $formatted;
            },
            $formula
        );

        if (! $valid || $resolved === null || str_contains($resolved, '{') || str_contains($resolved, '}')) {
            return null;
        }

        return $resolved;
    }

    private function parseFormulaExpression(string $formula, int &$offset): ?float
    {
        $value = $this->parseFormulaTerm($formula, $offset);

        if ($value === null) {
            return null;
        }

        while (true) {
            $this->skipFormulaWhitespace($formula, $offset);
            $operator = $formula[$offset] ?? null;

            if ($operator !== '+' && $operator !== '-') {
                return $value;
            }

            $offset++;
            $right = $this->parseFormulaTerm($formula, $offset);

            if ($right === null) {
                return null;
            }

            $value = $operator === '+' ? $value + $right : $value - $right;
        }
    }

    private function parseFormulaTerm(string $formula, int &$offset): ?float
    {
        $value = $this->parseFormulaFactor($formula, $offset);

        if ($value === null) {
            return null;
        }

        while (true) {
            $this->skipFormulaWhitespace($formula, $offset);
            $operator = $formula[$offset] ?? null;

            if ($operator !== '*' && $operator !== '/') {
                return $value;
            }

            $offset++;
            $right = $this->parseFormulaFactor($formula, $offset);

            if ($right === null || ($operator === '/' && abs($right) < PHP_FLOAT_EPSILON)) {
                return null;
            }

            $value = $operator === '*' ? $value * $right : $value / $right;
        }
    }

    private function parseFormulaFactor(string $formula, int &$offset): ?float
    {
        $this->skipFormulaWhitespace($formula, $offset);
        $operator = $formula[$offset] ?? null;

        if ($operator === '+' || $operator === '-') {
            $offset++;
            $value = $this->parseFormulaFactor($formula, $offset);

            return $value === null ? null : ($operator === '-' ? -$value : $value);
        }

        if ($operator === '(') {
            $offset++;
            $value = $this->parseFormulaExpression($formula, $offset);
            $this->skipFormulaWhitespace($formula, $offset);

            if (($formula[$offset] ?? null) !== ')') {
                return null;
            }

            $offset++;

            return $value;
        }

        return $this->parseFormulaNumber($formula, $offset);
    }

    private function parseFormulaNumber(string $formula, int &$offset): ?float
    {
        $remaining = substr($formula, $offset);

        if (! preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)/', $remaining, $matches)) {
            return null;
        }

        $offset += strlen($matches[0]);

        return (float) $matches[0];
    }

    private function skipFormulaWhitespace(string $formula, int &$offset): void
    {
        $length = strlen($formula);

        while ($offset < $length && ctype_space($formula[$offset])) {
            $offset++;
        }
    }
}
