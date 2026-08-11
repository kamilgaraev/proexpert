<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Database;

final class PostgresCheckConstraintDefinition
{
    private const SCALAR_TEXT_TYPE = '(?:text|character\s+varying|varchar|bpchar)';

    public static function canonical(string $definition): string
    {
        $canonical = self::normalizeScalarArrayComparison($definition);
        $canonical = strtolower(str_replace('"', '', $canonical));
        $canonical = preg_replace(
            '/::\s*'.self::SCALAR_TEXT_TYPE.'(?:\s*\[\s*\])?/i',
            '',
            $canonical,
        );
        $canonical = preg_replace('/\s+/', '', (string) $canonical);
        if (! str_starts_with((string) $canonical, 'check(') || ! str_ends_with((string) $canonical, ')')) {
            return (string) $canonical;
        }

        $expression = substr((string) $canonical, 6, -1);
        while (self::hasRedundantOuterParentheses($expression)) {
            $expression = substr($expression, 1, -1);
        }

        return 'check('.$expression.')';
    }

    private static function normalizeScalarArrayComparison(string $definition): string
    {
        $identifier = '[A-Za-z_][A-Za-z0-9_]*';
        $type = self::SCALAR_TEXT_TYPE;
        $pattern = '/(?:\(\s*(?<wrapped>"?'.$identifier.'"?)\s*\)|(?<plain>"?'.$identifier.'"?))'
            .'(?:\s*::\s*'.$type.')?\s*=\s*ANY\s*\(\s*(?:\(\s*)?ARRAY\s*\[(?<items>[^\[\]]*)\]'
            .'\s*(?:\)\s*)?(?:::\s*'.$type.'\s*\[\s*\])?\s*\)/i';

        return (string) preg_replace_callback($pattern, static function (array $match) use ($type): string {
            $column = ($match['wrapped'] ?? '') !== '' ? $match['wrapped'] : $match['plain'];
            $items = preg_replace('/::\s*'.$type.'/i', '', $match['items']);

            return $column.' IN ('.$items.')';
        }, $definition);
    }

    private static function hasRedundantOuterParentheses(string $expression): bool
    {
        if (! str_starts_with($expression, '(') || ! str_ends_with($expression, ')')) {
            return false;
        }
        $depth = 0;
        $quoted = false;
        $length = strlen($expression);
        for ($index = 0; $index < $length; $index++) {
            $character = $expression[$index];
            if ($character === "'" && ($index === 0 || $expression[$index - 1] !== '\\')) {
                $quoted = ! $quoted;
            }
            if ($quoted) {
                continue;
            }
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
                if ($depth === 0 && $index !== $length - 1) {
                    return false;
                }
            }
        }

        return $depth === 0 && ! $quoted;
    }
}
