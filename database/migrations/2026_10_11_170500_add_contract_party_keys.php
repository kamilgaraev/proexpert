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
        Schema::table('contract_parties', static function (Blueprint $table): void { $table->string('party_key', 36)->nullable(); });
        DB::statement('UPDATE contract_parties SET party_key = side');
        DB::statement('ALTER TABLE contract_parties ALTER COLUMN party_key SET NOT NULL, ALTER COLUMN side DROP NOT NULL');
        Schema::table('contract_parties', static function (Blueprint $table): void { $table->unique(['contract_id', 'party_key']); });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION assign_contract_party_key() RETURNS trigger AS $$
BEGIN
    NEW.party_key := coalesce(NEW.party_key, NEW.side, gen_random_uuid()::text);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_party_key_default BEFORE INSERT ON contract_parties
FOR EACH ROW EXECUTE FUNCTION assign_contract_party_key();
ALTER TABLE contract_parties ADD CONSTRAINT contract_party_key_shape CHECK (
    party_key ~ '^(first|second|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})$'
    AND (side IS NULL OR side IN ('first', 'second')) AND (side IS NULL OR side = party_key)
);
SQL);
    }

    public function down(): void
    {
        if (DB::table('contract_parties')->whereNull('side')->exists()) { throw new RuntimeException('Откат недоступен: договоры содержат дополнительные стороны.'); }
        DB::unprepared('DROP TRIGGER contract_party_key_default ON contract_parties; DROP FUNCTION assign_contract_party_key(); ALTER TABLE contract_parties DROP CONSTRAINT contract_party_key_shape, ALTER COLUMN side SET NOT NULL;');
        Schema::table('contract_parties', static function (Blueprint $table): void { $table->dropUnique(['contract_id', 'party_key']); $table->dropColumn('party_key'); });
    }
};
