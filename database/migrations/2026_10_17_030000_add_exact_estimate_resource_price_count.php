<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }
        $this->assertNoTransaction($connection);
        $connection->transaction(function () use ($connection): void {
            $this->configureTransaction($connection);
            if ($connection->selectOne("SELECT to_regclass('public.estimate_resource_prices') IS NOT NULL AS present")->present !== true) {
                return;
            }
            $connection->statement('LOCK TABLE public.estimate_resource_prices IN SHARE ROW EXCLUSIVE MODE');
            $source = $connection->selectOne("SELECT c.relkind,c.relispartition,c.relrowsecurity,c.relforcerowsecurity,
                current_setting('server_version_num')::int AS version,current_setting('max_prepared_transactions')::int AS prepared,
                pg_is_in_recovery() AS recovery,EXISTS(SELECT 1 FROM pg_catalog.pg_inherits i WHERE i.inhrelid=c.oid OR i.inhparent=c.oid) AS inheritance,
                EXISTS(SELECT 1 FROM pg_catalog.pg_subscription_rel s WHERE s.srrelid=c.oid) AS subscribed,
                EXISTS(SELECT 1 FROM pg_catalog.pg_rewrite r WHERE r.ev_class=c.oid AND r.rulename<>'_RETURN') AS rewritten
                FROM pg_catalog.pg_class c WHERE c.oid=to_regclass('public.estimate_resource_prices')");
            if ($source === null || $source->relkind !== 'r' || $source->relispartition || $source->relrowsecurity || $source->relforcerowsecurity
                || $source->version < 160000 || $source->prepared !== 0 || $source->recovery || $source->inheritance || $source->subscribed || $source->rewritten) {
                throw new RuntimeException('Exact estimate price count requires an ordinary primary table without RLS, rewrite, subscriptions or prepared transactions');
            }
            $connection->statement('CREATE TABLE public.estimate_resource_price_count_deltas (slot integer PRIMARY KEY, delta numeric NOT NULL)');
            $connection->statement('INSERT INTO public.estimate_resource_price_count_deltas SELECT -1,count(*)::numeric FROM public.estimate_resource_prices');
            $connection->unprepared(<<<'SQL'
CREATE FUNCTION public.eg_resource_price_count_delta() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE own_slot integer; amount numeric;
BEGIN
    IF TG_RELID <> 'public.estimate_resource_prices'::regclass OR TG_NARGS <> 0 OR TG_LEVEL <> 'STATEMENT' OR TG_WHEN <> 'AFTER' THEN
        RAISE EXCEPTION 'Unexpected estimate price counter trigger context';
    END IF;
    IF TG_OP = 'TRUNCATE' THEN
        TRUNCATE TABLE public.estimate_resource_price_count_deltas;
        INSERT INTO public.estimate_resource_price_count_deltas SELECT -1,count(*)::numeric FROM ONLY public.estimate_resource_prices;
        RETURN NULL;
    END IF;
    IF TG_OP = 'INSERT' THEN
        SELECT count(*)::numeric INTO amount FROM new_price_rows;
    ELSIF TG_OP = 'DELETE' THEN
        SELECT -count(*)::numeric INTO amount FROM old_price_rows;
    ELSE
        RAISE EXCEPTION 'Unexpected estimate price counter operation';
    END IF;
    IF amount = 0 THEN RETURN NULL; END IF;
    SELECT backend_id INTO STRICT own_slot FROM pg_catalog.pg_stat_get_backend_idset() backend_id
    WHERE pg_catalog.pg_stat_get_backend_pid(backend_id)=pg_catalog.pg_backend_pid();
    INSERT INTO public.estimate_resource_price_count_deltas AS current_count (slot,delta) VALUES (own_slot,amount)
    ON CONFLICT(slot) DO UPDATE SET delta=current_count.delta+excluded.delta;
    RETURN NULL;
END; $$;
REVOKE ALL ON FUNCTION public.eg_resource_price_count_delta() FROM PUBLIC;
CREATE TRIGGER eg_resource_price_count_insert AFTER INSERT ON public.estimate_resource_prices
REFERENCING NEW TABLE AS new_price_rows FOR EACH STATEMENT EXECUTE FUNCTION public.eg_resource_price_count_delta();
CREATE TRIGGER eg_resource_price_count_delete AFTER DELETE ON public.estimate_resource_prices
REFERENCING OLD TABLE AS old_price_rows FOR EACH STATEMENT EXECUTE FUNCTION public.eg_resource_price_count_delta();
CREATE TRIGGER eg_resource_price_count_truncate AFTER TRUNCATE ON public.estimate_resource_prices
FOR EACH STATEMENT EXECUTE FUNCTION public.eg_resource_price_count_delta();
ALTER TABLE public.estimate_resource_prices ENABLE ALWAYS TRIGGER eg_resource_price_count_insert;
ALTER TABLE public.estimate_resource_prices ENABLE ALWAYS TRIGGER eg_resource_price_count_delete;
ALTER TABLE public.estimate_resource_prices ENABLE ALWAYS TRIGGER eg_resource_price_count_truncate;
SQL);
        });
    }

    public function down(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }
        $this->assertNoTransaction($connection);
        $connection->transaction(function () use ($connection): void {
            $this->configureTransaction($connection);
            if ($connection->selectOne("SELECT to_regclass('public.estimate_resource_prices') IS NOT NULL AS present")->present === true) {
                $connection->statement('LOCK TABLE public.estimate_resource_prices IN ACCESS EXCLUSIVE MODE');
                $connection->unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_resource_price_count_insert ON public.estimate_resource_prices;
DROP TRIGGER IF EXISTS eg_resource_price_count_delete ON public.estimate_resource_prices;
DROP TRIGGER IF EXISTS eg_resource_price_count_truncate ON public.estimate_resource_prices;
SQL);
            }
            $connection->unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS public.eg_resource_price_count_delta();
DROP TABLE IF EXISTS public.estimate_resource_price_count_deltas;
SQL);
        });
    }

    private function assertNoTransaction(Connection $connection): void
    {
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new RuntimeException('Exact estimate price count migration requires its own transaction');
        }
    }

    private function configureTransaction(Connection $connection): void
    {
        $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $connection->statement("SET LOCAL lock_timeout='5s'");
        $connection->statement("SET LOCAL statement_timeout='30s'");
    }
};
