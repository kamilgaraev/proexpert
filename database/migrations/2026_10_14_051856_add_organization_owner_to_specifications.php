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
        Schema::table('specifications', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->index()->constrained()->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
UPDATE specifications AS specification
SET organization_id = owners.organization_id
FROM (
    SELECT link.specification_id, MIN(contract.organization_id) AS organization_id
    FROM contract_specification AS link
    JOIN contracts AS contract ON contract.id = link.contract_id
    GROUP BY link.specification_id
    HAVING COUNT(DISTINCT contract.organization_id) = 1
) AS owners
WHERE specification.id = owners.specification_id
    AND specification.organization_id IS NULL
    AND specification.builder_revision_id IS NULL
    AND specification.supplementary_document_id IS NULL;

CREATE FUNCTION guard_specification_organization_owner() RETURNS trigger AS $$
BEGIN
    IF NEW.organization_id IS DISTINCT FROM OLD.organization_id THEN
        RAISE EXCEPTION 'specification_organization_owner_immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER specification_organization_owner_guard BEFORE UPDATE ON specifications
FOR EACH ROW EXECUTE FUNCTION guard_specification_organization_owner();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS specification_organization_owner_guard ON specifications; DROP FUNCTION IF EXISTS guard_specification_organization_owner();');
        Schema::table('specifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
