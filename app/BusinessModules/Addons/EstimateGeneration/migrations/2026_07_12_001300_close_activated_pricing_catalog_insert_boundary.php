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

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_active_resource_price_immutable ON estimate_resource_prices;
CREATE OR REPLACE FUNCTION eg_pricing_catalog_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE catalog_status text; version_id bigint;
BEGIN
    IF TG_TABLE_NAME='estimate_resource_prices' THEN
      version_id := CASE WHEN TG_OP='DELETE' THEN OLD.regional_price_version_id ELSE NEW.regional_price_version_id END;
      IF version_id IS NULL THEN RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END; END IF;
      SELECT status INTO catalog_status FROM estimate_regional_price_versions WHERE id=version_id;
      IF catalog_status IS DISTINCT FROM 'active' THEN RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END; END IF;
    END IF;
    RAISE EXCEPTION 'estimate_generation.activated_pricing_catalog_is_immutable';
END; $$;
CREATE TRIGGER eg_active_resource_price_immutable BEFORE INSERT OR UPDATE OR DELETE ON estimate_resource_prices
FOR EACH ROW EXECUTE FUNCTION eg_pricing_catalog_immutable_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_active_resource_price_immutable ON estimate_resource_prices;
CREATE TRIGGER eg_active_resource_price_immutable BEFORE UPDATE OR DELETE ON estimate_resource_prices
FOR EACH ROW WHEN (OLD.regional_price_version_id IS NOT NULL) EXECUTE FUNCTION eg_pricing_catalog_immutable_guard();
SQL);
    }
};
