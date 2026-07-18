<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $definition = $this->definition('public.eg_expected_package_item_price_v2(bigint)');
        if ($this->hasParenthesizedEvidenceUnit($definition)) {
            return;
        }
        $definition = $this->replaceOnce(
            $definition,
            "/\\|\\|\\s*evidence\\.value\\s*->>\\s*'unit'\\s*\\|\\|/i",
            "||(evidence.value->>'unit')||",
            'estimate_generation.pricing_evidence_unit_precedence_contract_changed',
        );
        DB::unprepared($definition);
    }

    public function down(): void {}

    private function hasParenthesizedEvidenceUnit(string $definition): bool
    {
        return preg_match("/\\|\\|\\s*\\(evidence\\.value\\s*->>\\s*'unit'\\)\\s*\\|\\|/i", $definition) === 1;
    }

    private function definition(string $signature): string
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('{$signature}'::regprocedure)");
        if (! is_string($definition) || $definition === '') {
            throw new RuntimeException('estimate_generation.pricing_function_missing');
        }

        return $definition;
    }

    private function replaceOnce(string $source, string $pattern, string $replacement, string $error): string
    {
        $updated = preg_replace($pattern, $replacement, $source, 1, $count);
        if (! is_string($updated) || $count !== 1) {
            throw new RuntimeException($error);
        }

        return $updated;
    }
};
