<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Database;

final class PostgresCheckConstraintDefinition
{
    public static function canonical(string $definition): string
    {
        $tokens = self::tokenize($definition);
        if ($tokens === null
            || count($tokens) < 4
            || $tokens[0] !== 'check'
            || $tokens[1] !== '('
            || ! self::wrappedBy($tokens, 1, count($tokens) - 1)) {
            return self::incompatible($definition);
        }

        $expression = array_slice($tokens, 2, -1);
        $expression = self::withoutTextCasts($expression);
        $expression = self::normalizeScalarArrayComparisons($expression);
        if ($expression === null || ! self::balanced($expression)) {
            return self::incompatible($definition);
        }
        while (self::wrappedBy($expression, 0, count($expression) - 1)) {
            $expression = array_slice($expression, 1, -1);
        }

        return 'check('.implode(' ', $expression).')';
    }

    /** @return list<string>|null */
    private static function tokenize(string $definition): ?array
    {
        $tokens = [];
        $length = strlen($definition);
        for ($index = 0; $index < $length;) {
            $character = $definition[$index];
            if (ctype_space($character)) {
                $index++;

                continue;
            }
            if (($character === 'E' || $character === 'e')
                && $index + 1 < $length && $definition[$index + 1] === "'") {
                $literal = self::quotedToken($definition, $index + 1, "'", true);
                if ($literal === null) {
                    return null;
                }
                [$value, $index] = $literal;
                $tokens[] = '@e['.bin2hex($value).']';

                continue;
            }
            if ($character === "'") {
                $literal = self::quotedToken($definition, $index, "'", false);
                if ($literal === null) {
                    return null;
                }
                [$value, $index] = $literal;
                $tokens[] = '@s['.bin2hex($value).']';

                continue;
            }
            if ($character === '"') {
                $identifier = self::quotedToken($definition, $index, '"', false);
                if ($identifier === null) {
                    return null;
                }
                [$value, $index] = $identifier;
                $tokens[] = preg_match('/^[a-z_][a-z0-9_]*$/D', $value) === 1
                    ? $value
                    : '@q['.bin2hex($value).']';

                continue;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/A', $definition, $match, 0, $index) === 1) {
                $tokens[] = strtolower($match[0]);
                $index += strlen($match[0]);

                continue;
            }
            if (preg_match('/\G(?:\d+(?:\.\d+)?|\.\d+)/A', $definition, $match, 0, $index) === 1) {
                $tokens[] = $match[0];
                $index += strlen($match[0]);

                continue;
            }
            $operator = substr($definition, $index, 2);
            if (in_array($operator, ['--', '/*', '*/'], true)) {
                return null;
            }
            if (in_array($operator, ['::', '<=', '>=', '<>', '!='], true)) {
                $tokens[] = $operator;
                $index += 2;

                continue;
            }
            if (str_contains('()[],=~<>+-*/.', $character)) {
                $tokens[] = $character;
                $index++;

                continue;
            }

            return null;
        }

        return $tokens;
    }

    /** @return array{string, int}|null */
    private static function quotedToken(string $sql, int $quoteIndex, string $quote, bool $backslashEscapes): ?array
    {
        $value = '';
        $length = strlen($sql);
        for ($index = $quoteIndex + 1; $index < $length; $index++) {
            $character = $sql[$index];
            if ($backslashEscapes && $character === '\\') {
                if ($index + 1 >= $length) {
                    return null;
                }
                $value .= $character.$sql[++$index];

                continue;
            }
            if ($character !== $quote) {
                $value .= $character;

                continue;
            }
            if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                $value .= $quote.$quote;
                $index++;

                continue;
            }

            return [$value, $index + 1];
        }

        return null;
    }

    /** @param list<string> $tokens
     * @return list<string>
     */
    private static function withoutTextCasts(array $tokens): array
    {
        $result = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            if ($tokens[$index] !== '::') {
                $result[] = $tokens[$index];

                continue;
            }
            $end = self::textCastEnd($tokens, $index + 1);
            if ($end === null) {
                $result[] = $tokens[$index];

                continue;
            }
            $index = $end - 1;
        }

        return $result;
    }

    /** @param list<string> $tokens */
    private static function textCastEnd(array $tokens, int $start): ?int
    {
        $end = match ($tokens[$start] ?? null) {
            'text', 'varchar', 'bpchar' => $start + 1,
            'character' => ($tokens[$start + 1] ?? null) === 'varying' ? $start + 2 : null,
            default => null,
        };
        if ($end === null) {
            return null;
        }
        if (($tokens[$end] ?? null) === '[' && ($tokens[$end + 1] ?? null) === ']') {
            $end += 2;
        }

        return $end;
    }

    /** @param list<string> $tokens
     * @return list<string>|null
     */
    private static function normalizeScalarArrayComparisons(array $tokens): ?array
    {
        for ($index = 0; $index < count($tokens); $index++) {
            if (($tokens[$index] ?? null) !== 'any' || ($tokens[$index - 1] ?? null) !== '=') {
                continue;
            }
            $operandEnd = $index - 2;
            $operandStart = $operandEnd;
            if (($tokens[$operandEnd] ?? null) === ')') {
                $operandStart = self::matchingOpen($tokens, $operandEnd, '(', ')');
                if ($operandStart === null) {
                    return null;
                }
            }
            $operand = array_slice($tokens, $operandStart, $operandEnd - $operandStart + 1);
            while (self::wrappedBy($operand, 0, count($operand) - 1)) {
                $operand = array_slice($operand, 1, -1);
            }
            if (count($operand) !== 1 || ! self::identifierToken($operand[0])) {
                return null;
            }
            if (($tokens[$index + 1] ?? null) !== '(') {
                return null;
            }
            $anyEnd = self::matchingClose($tokens, $index + 1, '(', ')');
            if ($anyEnd === null) {
                return null;
            }
            $array = array_slice($tokens, $index + 2, $anyEnd - $index - 2);
            while (self::wrappedBy($array, 0, count($array) - 1)) {
                $array = array_slice($array, 1, -1);
            }
            if (($array[0] ?? null) !== 'array' || ($array[1] ?? null) !== '['
                || ! self::wrappedBy($array, 1, count($array) - 1, '[', ']')) {
                return null;
            }
            $items = array_slice($array, 2, -1);
            if (! self::scalarArrayItems($items)) {
                return null;
            }
            array_splice($tokens, $operandStart, $anyEnd - $operandStart + 1, [
                $operand[0], 'in', '(', ...$items, ')',
            ]);
            $index = $operandStart;
        }

        return $tokens;
    }

    /** @param list<string> $tokens */
    private static function scalarArrayItems(array $tokens): bool
    {
        if ($tokens === []) {
            return false;
        }
        $expectValue = true;
        foreach ($tokens as $token) {
            if ($expectValue) {
                if (! str_starts_with($token, '@s[')
                    && ! str_starts_with($token, '@e[')
                    && $token !== 'null'
                    && preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)$/D', $token) !== 1) {
                    return false;
                }
            } elseif ($token !== ',') {
                return false;
            }
            $expectValue = ! $expectValue;
        }

        return ! $expectValue;
    }

    private static function identifierToken(string $token): bool
    {
        return preg_match('/^[a-z_][a-z0-9_]*$/D', $token) === 1 || str_starts_with($token, '@q[');
    }

    /** @param list<string> $tokens */
    private static function balanced(array $tokens): bool
    {
        $stack = [];
        foreach ($tokens as $token) {
            if ($token === '(' || $token === '[') {
                $stack[] = $token;
            } elseif ($token === ')' || $token === ']') {
                $open = array_pop($stack);
                if (($token === ')' && $open !== '(') || ($token === ']' && $open !== '[')) {
                    return false;
                }
            }
        }

        return $stack === [];
    }

    /** @param list<string> $tokens */
    private static function wrappedBy(
        array $tokens,
        int $start,
        int $end,
        string $open = '(',
        string $close = ')',
    ): bool {
        if ($start < 0 || $end <= $start || ($tokens[$start] ?? null) !== $open || ($tokens[$end] ?? null) !== $close) {
            return false;
        }

        return self::matchingClose($tokens, $start, $open, $close) === $end;
    }

    /** @param list<string> $tokens */
    private static function matchingClose(array $tokens, int $start, string $open, string $close): ?int
    {
        $depth = 0;
        for ($index = $start; $index < count($tokens); $index++) {
            if ($tokens[$index] === $open) {
                $depth++;
            } elseif ($tokens[$index] === $close && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<string> $tokens */
    private static function matchingOpen(array $tokens, int $end, string $open, string $close): ?int
    {
        $depth = 0;
        for ($index = $end; $index >= 0; $index--) {
            if ($tokens[$index] === $close) {
                $depth++;
            } elseif ($tokens[$index] === $open && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    private static function incompatible(string $definition): string
    {
        return 'incompatible:'.hash('sha256', $definition);
    }
}
