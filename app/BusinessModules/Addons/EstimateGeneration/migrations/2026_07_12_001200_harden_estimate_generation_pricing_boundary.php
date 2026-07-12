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
        Schema::create('estimate_generation_unit_conversions', function (Blueprint $table): void {
            $table->id();
            $table->string('from_unit', 50);
            $table->string('to_unit', 50);
            $table->decimal('factor', 30, 12);
            $table->unsignedInteger('version');
            $table->char('fingerprint', 64);
            $table->timestampsTz();
            $table->unique(['from_unit', 'to_unit', 'version'], 'eg_unit_conversion_version_uq');
        });

        Schema::table('estimate_generation_package_items', function (Blueprint $table): void {
            $table->string('logical_key', 180)->nullable();
            $table->unsignedInteger('revision')->nullable();
            $table->foreignId('supersedes_item_id')->nullable()->constrained('estimate_generation_package_items')->restrictOnDelete();
            $table->unsignedBigInteger('quantity_evidence_id')->nullable();
            $table->char('quantity_evidence_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('estimate_norm_id')->nullable();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->unsignedBigInteger('price_zone_id')->nullable();
            $table->unsignedBigInteger('period_id')->nullable();
            $table->unsignedBigInteger('regional_price_version_id')->nullable();
            $table->unique(['package_id', 'logical_key', 'revision'], 'eg_package_item_revision_uq');
        });

        Schema::create('estimate_generation_package_item_price_inputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_item_id')->constrained('estimate_generation_package_items')->restrictOnDelete();
            $table->foreignId('norm_resource_id')->constrained('estimate_norm_resources')->restrictOnDelete();
            $table->foreignId('resource_price_id')->constrained('estimate_resource_prices')->restrictOnDelete();
            $table->foreignId('unit_conversion_id')->nullable()->constrained('estimate_generation_unit_conversions')->restrictOnDelete();
            $table->unsignedInteger('ordinal');
            $table->timestampsTz();
            $table->unique(['package_item_id', 'norm_resource_id'], 'eg_item_price_norm_resource_uq');
            $table->unique(['package_item_id', 'ordinal'], 'eg_item_price_ordinal_uq');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_package_items DROP CONSTRAINT IF EXISTS eg_package_items_price_snapshot_shape_ck;
ALTER TABLE estimate_generation_package_items DROP CONSTRAINT IF EXISTS eg_package_items_price_snapshot_required_ck;
ALTER TABLE estimate_generation_package_items DROP CONSTRAINT IF EXISTS estimate_generation_package_items_package_id_key_unique;
DROP FUNCTION IF EXISTS eg_price_resource_evidence_valid(jsonb);

CREATE FUNCTION eg_pricing_catalog_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE catalog_status text;
BEGIN
    IF TG_TABLE_NAME='estimate_resource_prices' THEN
      SELECT status INTO catalog_status FROM estimate_regional_price_versions WHERE id=OLD.regional_price_version_id;
      IF catalog_status IS DISTINCT FROM 'active' THEN RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END; END IF;
    END IF;
    RAISE EXCEPTION 'estimate_generation.activated_pricing_catalog_is_immutable';
END; $$;
CREATE TRIGGER eg_active_resource_price_immutable BEFORE UPDATE OR DELETE ON estimate_resource_prices
FOR EACH ROW WHEN (OLD.regional_price_version_id IS NOT NULL) EXECUTE FUNCTION eg_pricing_catalog_immutable_guard();
CREATE TRIGGER eg_unit_conversion_immutable BEFORE UPDATE OR DELETE ON estimate_generation_unit_conversions
FOR EACH ROW EXECUTE FUNCTION eg_pricing_catalog_immutable_guard();

CREATE FUNCTION eg_package_item_priced_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' AND OLD.price_snapshot IS NOT NULL THEN RAISE EXCEPTION 'estimate_generation.priced_package_item_is_immutable'; END IF;
    IF OLD.price_snapshot IS NOT NULL AND ROW(
        OLD.package_id, OLD.logical_key, OLD.revision, OLD.supersedes_item_id,
        OLD.quantity_evidence_id, OLD.quantity_evidence_fingerprint, OLD.estimate_norm_id,
        OLD.region_id, OLD.price_zone_id, OLD.period_id, OLD.regional_price_version_id,
        OLD.quantity, OLD.unit, OLD.price_snapshot, OLD.price_source, OLD.unit_price,
        OLD.direct_cost, OLD.overhead_cost, OLD.profit_cost, OLD.total_cost
    ) IS DISTINCT FROM ROW(
        NEW.package_id, NEW.logical_key, NEW.revision, NEW.supersedes_item_id,
        NEW.quantity_evidence_id, NEW.quantity_evidence_fingerprint, NEW.estimate_norm_id,
        NEW.region_id, NEW.price_zone_id, NEW.period_id, NEW.regional_price_version_id,
        NEW.quantity, NEW.unit, NEW.price_snapshot, NEW.price_source, NEW.unit_price,
        NEW.direct_cost, NEW.overhead_cost, NEW.profit_cost, NEW.total_cost
    ) THEN RAISE EXCEPTION 'estimate_generation.priced_package_item_is_immutable'; END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER eg_package_item_priced_immutable BEFORE UPDATE OR DELETE ON estimate_generation_package_items
FOR EACH ROW EXECUTE FUNCTION eg_package_item_priced_immutable_guard();

CREATE FUNCTION eg_package_item_price_input_append_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'estimate_generation.package_item_price_input_is_immutable';
END; $$;
CREATE TRIGGER eg_package_item_price_input_append BEFORE UPDATE OR DELETE ON estimate_generation_package_item_price_inputs
FOR EACH ROW EXECUTE FUNCTION eg_package_item_price_input_append_guard();

CREATE FUNCTION eg_package_item_price_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    evidence record; package_scope record; expected_count bigint; actual_count bigint; computed numeric(30,2);
    canonical text; expected_hash text;
BEGIN
    IF NEW.price_snapshot IS NULL THEN RETURN NEW; END IF;
    IF NEW.logical_key IS NULL OR NEW.revision IS NULL OR NEW.quantity_evidence_id IS NULL
       OR NEW.quantity_evidence_fingerprint IS NULL OR NEW.estimate_norm_id IS NULL
       OR NEW.region_id IS NULL OR NEW.price_zone_id IS NULL OR NEW.period_id IS NULL
       OR NEW.regional_price_version_id IS NULL THEN
        RAISE EXCEPTION 'estimate_generation.priced_item_trust_input_missing';
    END IF;
    SELECT s.organization_id, s.project_id, s.id AS session_id INTO package_scope
      FROM estimate_generation_packages p JOIN estimate_generation_sessions s ON s.id=p.session_id WHERE p.id=NEW.package_id;
    SELECT * INTO evidence FROM estimate_generation_evidence e
      WHERE e.id=NEW.quantity_evidence_id AND e.organization_id=package_scope.organization_id
        AND e.project_id=package_scope.project_id AND e.session_id=package_scope.session_id
        AND e.type='work_item' AND e.invalidated_at IS NULL AND e.fingerprint=NEW.quantity_evidence_fingerprint;
    IF evidence.id IS NULL OR (evidence.value->>'quantity')::numeric <= 0
       OR evidence.value->>'unit' IS DISTINCT FROM NEW.unit OR (evidence.value->>'quantity')::numeric IS DISTINCT FROM NEW.quantity THEN
        RAISE EXCEPTION 'estimate_generation.quantity_evidence_mismatch';
    END IF;
    SELECT count(*) INTO expected_count FROM estimate_norm_resources WHERE estimate_norm_id=NEW.estimate_norm_id;
    SELECT count(*) INTO actual_count FROM estimate_generation_package_item_price_inputs WHERE package_item_id=NEW.id;
    IF expected_count=0 OR actual_count<>expected_count THEN RAISE EXCEPTION 'estimate_generation.norm_resource_set_mismatch'; END IF;
    IF EXISTS (
      SELECT 1 FROM estimate_generation_package_item_price_inputs i
      JOIN estimate_norm_resources nr ON nr.id=i.norm_resource_id
      JOIN estimate_resource_prices rp ON rp.id=i.resource_price_id
      LEFT JOIN estimate_generation_unit_conversions c ON c.id=i.unit_conversion_id
      WHERE i.package_item_id=NEW.id AND (
        nr.estimate_norm_id<>NEW.estimate_norm_id OR rp.resource_code IS DISTINCT FROM nr.resource_code
        OR rp.region_id<>NEW.region_id OR rp.price_zone_id<>NEW.price_zone_id OR rp.period_id<>NEW.period_id
        OR rp.regional_price_version_id<>NEW.regional_price_version_id OR rp.base_price IS NULL OR rp.base_price<=0
        OR NOT EXISTS (SELECT 1 FROM estimate_regional_price_versions v WHERE v.id=rp.regional_price_version_id AND v.status='active')
        OR (rp.unit IS DISTINCT FROM nr.unit AND (c.id IS NULL OR c.from_unit IS DISTINCT FROM nr.unit OR c.to_unit IS DISTINCT FROM rp.unit OR c.factor<=0))
        OR (rp.unit IS NOT DISTINCT FROM nr.unit AND c.id IS NOT NULL)
      )
    ) THEN RAISE EXCEPTION 'estimate_generation.price_input_mismatch'; END IF;
    SELECT round(sum(nr.quantity * NEW.quantity * COALESCE(c.factor,1) * rp.base_price),2),
           string_agg(i.norm_resource_id||':'||i.resource_price_id||':'||COALESCE(i.unit_conversion_id::text,'identity'), '|' ORDER BY i.ordinal)
      INTO computed, canonical
      FROM estimate_generation_package_item_price_inputs i
      JOIN estimate_norm_resources nr ON nr.id=i.norm_resource_id
      JOIN estimate_resource_prices rp ON rp.id=i.resource_price_id
      LEFT JOIN estimate_generation_unit_conversions c ON c.id=i.unit_conversion_id
      WHERE i.package_item_id=NEW.id;
    expected_hash := encode(sha256(convert_to(canonical||'|'||NEW.quantity_evidence_id||':'||NEW.quantity_evidence_fingerprint,'UTF8')),'hex');
    IF NEW.direct_cost<>computed OR NEW.total_cost<>computed OR NEW.unit_price<>round(computed/NEW.quantity,6)
       OR NEW.overhead_cost<>0 OR NEW.profit_cost<>0 OR NEW.price_snapshot->>'source_reference'<>'sha256:'||expected_hash
       OR (NEW.price_snapshot->>'final_amount')::numeric<>computed OR (NEW.price_snapshot->'coefficients'->>'work_cost')::numeric<>0 THEN
        RAISE EXCEPTION 'estimate_generation.database_built_price_mismatch';
    END IF;
    RETURN NEW;
END; $$;

CREATE FUNCTION eg_finalize_package_item_price(p_item_id bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    item record; evidence record; computed numeric(30,2); canonical text; snapshot jsonb;
BEGIN
    SELECT * INTO item FROM estimate_generation_package_items WHERE id=p_item_id FOR UPDATE;
    IF item.id IS NULL OR item.price_snapshot IS NOT NULL THEN RAISE EXCEPTION 'estimate_generation.price_finalize_state_invalid'; END IF;
    SELECT * INTO evidence FROM estimate_generation_evidence WHERE id=item.quantity_evidence_id AND invalidated_at IS NULL;
    SELECT round(sum(nr.quantity * (evidence.value->>'quantity')::numeric * COALESCE(c.factor,1) * rp.base_price),2),
           string_agg(i.norm_resource_id||':'||i.resource_price_id||':'||COALESCE(i.unit_conversion_id::text,'identity'), '|' ORDER BY i.ordinal)
      INTO computed, canonical
      FROM estimate_generation_package_item_price_inputs i
      JOIN estimate_norm_resources nr ON nr.id=i.norm_resource_id
      JOIN estimate_resource_prices rp ON rp.id=i.resource_price_id
      LEFT JOIN estimate_generation_unit_conversions c ON c.id=i.unit_conversion_id
      WHERE i.package_item_id=p_item_id;
    IF computed IS NULL OR computed<=0 THEN RAISE EXCEPTION 'estimate_generation.price_inputs_missing'; END IF;
    canonical := canonical||'|'||item.quantity_evidence_id||':'||item.quantity_evidence_fingerprint;
    snapshot := jsonb_build_object(
      'region_id',item.region_id,'zone_id',item.price_zone_id,'period_id',item.period_id,'version_id',item.regional_price_version_id,
      'source_type','regional_resource_aggregate','source_reference','sha256:'||encode(sha256(convert_to(canonical,'UTF8')),'hex'),
      'base_amount',to_char(computed,'FM999999999999999999999999990.00'),
      'coefficients',jsonb_build_object('work_cost','0.00','quantity_evidence_id',item.quantity_evidence_id,'quantity_evidence_fingerprint',item.quantity_evidence_fingerprint),
      'final_amount',to_char(computed,'FM999999999999999999999999990.00'),'currency','RUB','captured_at',to_char(clock_timestamp(),'YYYY-MM-DD"T"HH24:MI:SSOF')
    );
    UPDATE estimate_generation_package_items SET
      quantity=(evidence.value->>'quantity')::numeric, unit=evidence.value->>'unit', price_source='regional_catalog',
      unit_price=round(computed/(evidence.value->>'quantity')::numeric,6), direct_cost=computed,
      overhead_cost=0, profit_cost=0, total_cost=computed, price_snapshot=snapshot
      WHERE id=p_item_id;
END; $$;
CREATE CONSTRAINT TRIGGER eg_package_item_price_validate AFTER INSERT OR UPDATE ON estimate_generation_package_items
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION eg_package_item_price_validate();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS eg_package_item_price_validate ON estimate_generation_package_items; DROP FUNCTION IF EXISTS eg_package_item_price_validate(); DROP FUNCTION IF EXISTS eg_finalize_package_item_price(bigint); DROP TRIGGER IF EXISTS eg_package_item_price_input_append ON estimate_generation_package_item_price_inputs; DROP FUNCTION IF EXISTS eg_package_item_price_input_append_guard(); DROP TRIGGER IF EXISTS eg_package_item_priced_immutable ON estimate_generation_package_items; DROP FUNCTION IF EXISTS eg_package_item_priced_immutable_guard(); DROP TRIGGER IF EXISTS eg_active_resource_price_immutable ON estimate_resource_prices; DROP TRIGGER IF EXISTS eg_unit_conversion_immutable ON estimate_generation_unit_conversions; DROP FUNCTION IF EXISTS eg_pricing_catalog_immutable_guard();');
        }
        Schema::dropIfExists('estimate_generation_package_item_price_inputs');
        Schema::table('estimate_generation_package_items', function (Blueprint $table): void {
            $table->dropUnique('eg_package_item_revision_uq');
            $table->dropColumn(['logical_key', 'revision', 'supersedes_item_id', 'quantity_evidence_id', 'quantity_evidence_fingerprint', 'estimate_norm_id', 'region_id', 'price_zone_id', 'period_id', 'regional_price_version_id']);
            $table->unique(['package_id', 'key'], 'estimate_generation_package_items_package_id_key_unique');
        });
        Schema::dropIfExists('estimate_generation_unit_conversions');
    }
};
