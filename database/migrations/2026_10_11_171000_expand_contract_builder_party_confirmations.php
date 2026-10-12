<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->documentConstraints('BETWEEN 2 AND 20');
        foreach ($this->tables() as [$table, $guard, $shape, $owner]) {
            Schema::table($table, static function (Blueprint $blueprint): void { $blueprint->string('party_key', 36)->nullable(); });
            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$guard}");
            DB::statement("UPDATE {$table} SET party_key = side");
            DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$guard}");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN party_key SET NOT NULL, ALTER COLUMN side DROP NOT NULL");
            Schema::table($table, static function (Blueprint $blueprint) use ($owner, $table): void { $blueprint->unique([$owner, 'party_key'], $table.'_party_key_unique'); });
            $this->confirmationConstraint($table, $shape, true);
        }
    }

    public function down(): void
    {
        foreach (['contract_builder_revisions', 'contract_supplementary_documents'] as $table) {
            if (DB::table($table)->whereRaw('jsonb_array_length(parties) <> 2')->exists()) { throw new RuntimeException('Сначала сохраните многосторонние договоры: откат не должен удалять их участников.'); }
        }
        foreach ($this->tables() as [$table, , $shape]) {
            if (DB::table($table)->whereNull('side')->exists()) { throw new RuntimeException('Откат недоступен: сохранены подтверждения дополнительных сторон.'); }
            $this->confirmationConstraint($table, $shape, false);
            DB::statement("ALTER TABLE {$table} ALTER COLUMN side SET NOT NULL");
            Schema::table($table, static function (Blueprint $blueprint) use ($table): void { $blueprint->dropUnique($table.'_party_key_unique'); $blueprint->dropColumn('party_key'); });
        }
        $this->documentConstraints('= 2');
    }

    private function tables(): array
    {
        return [
            ['contract_revision_confirmations', 'contract_revision_confirmation_guard', 'contract_confirmation_shape', 'revision_id'],
            ['contract_supplementary_confirmations', 'contract_supplementary_confirmation_guard', 'contract_supplementary_confirmation_shape', 'document_id'],
        ];
    }

    private function documentConstraints(string $count): void
    {
        DB::statement('ALTER TABLE contract_builder_revisions DROP CONSTRAINT contract_builder_revision_shape');
        DB::statement("ALTER TABLE contract_builder_revisions ADD CONSTRAINT contract_builder_revision_shape CHECK (
            revision_number > 0 AND ((revision_number = 1 AND base_revision_id IS NULL) OR (revision_number > 1 AND base_revision_id IS NOT NULL))
            AND jsonb_typeof(document) = 'object' AND jsonb_typeof(definitions) = 'object' AND jsonb_typeof(values) = 'object' AND jsonb_typeof(blocks) = 'object'
            AND jsonb_typeof(parties) = 'array' AND jsonb_array_length(parties) {$count} AND jsonb_typeof(attachments) = 'array'
        )");
        DB::statement('ALTER TABLE contract_supplementary_documents DROP CONSTRAINT contract_supplementary_shape');
        DB::statement("ALTER TABLE contract_supplementary_documents ADD CONSTRAINT contract_supplementary_shape CHECK (
            status IN ('draft', 'applied', 'cancelled')
            AND jsonb_typeof(frame_document) = 'object' AND jsonb_typeof(frame_definitions) = 'object'
            AND jsonb_typeof(revision_document) = 'object' AND jsonb_typeof(revision_definitions) = 'object'
            AND jsonb_typeof(parties) = 'array' AND jsonb_array_length(parties) {$count}
            AND jsonb_typeof(entity_snapshots) = 'object' AND jsonb_typeof(base_values) = 'object' AND jsonb_typeof(values) = 'object'
            AND ((status = 'applied' AND applied_at IS NOT NULL) OR (status <> 'applied' AND applied_at IS NULL))
        )");
    }

    private function confirmationConstraint(string $table, string $shape, bool $expanded): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$shape}");
        $identity = $expanded ? "party_key ~ '^(first|second|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})$' AND (side IS NULL OR side IN ('first', 'second')) AND (side IS NULL OR side = party_key)" : "side IN ('first', 'second')";
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$shape} CHECK ({$identity} AND (
            (kind = 'connected' AND party_organization_id IS NOT NULL AND party_organization_id = recorded_organization_id AND proof IS NULL)
            OR (kind = 'external' AND party_organization_id IS NULL AND basis IS NOT NULL AND length(trim(basis)) > 0 AND proof IS NOT NULL AND jsonb_typeof(proof) = 'object')
        ))");
    }
};
