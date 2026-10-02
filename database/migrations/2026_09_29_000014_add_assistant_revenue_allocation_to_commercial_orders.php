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
        Schema::table('commercial_orders', function (Blueprint $table): void {
            $table->jsonb('assistant_revenue_allocation')->nullable();
        });

        DB::unprepared(<<<'SQL'
CREATE FUNCTION preserve_assistant_revenue_allocation() RETURNS trigger
LANGUAGE plpgsql AS $body$
BEGIN
    IF OLD.assistant_revenue_allocation IS NOT NULL
        AND NEW.assistant_revenue_allocation IS DISTINCT FROM OLD.assistant_revenue_allocation THEN
        RAISE EXCEPTION 'Assistant revenue allocation is immutable' USING ERRCODE = '23000';
    END IF;
    RETURN NEW;
END;
$body$;
CREATE TRIGGER preserve_assistant_revenue_allocation
BEFORE UPDATE OF assistant_revenue_allocation ON commercial_orders
FOR EACH ROW EXECUTE FUNCTION preserve_assistant_revenue_allocation();
SQL);
    }

    public function down(): void
    {
        if (DB::table('commercial_orders')->whereNotNull('assistant_revenue_allocation')->exists()) {
            throw new RuntimeException('Assistant revenue allocations must be preserved.');
        }

        DB::unprepared('DROP TRIGGER preserve_assistant_revenue_allocation ON commercial_orders');
        DB::unprepared('DROP FUNCTION preserve_assistant_revenue_allocation()');
        Schema::table('commercial_orders', function (Blueprint $table): void {
            $table->dropColumn('assistant_revenue_allocation');
        });
    }
};
