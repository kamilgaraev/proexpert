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

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_sealed_estimate_header()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.current_version_id IS NOT NULL AND OLD.status = 'approved' THEN
                    IF NEW.structure_cache_path IS DISTINCT FROM OLD.structure_cache_path
                        AND (to_jsonb(NEW) - ARRAY['structure_cache_path', 'updated_at'])
                        = (to_jsonb(OLD) - ARRAY['structure_cache_path', 'updated_at']) THEN
                        RETURN NEW;
                    END IF;

                    IF NEW.current_version_id IS DISTINCT FROM OLD.current_version_id
                        AND (to_jsonb(NEW) - ARRAY['current_version_id', 'updated_at'])
                            = (to_jsonb(OLD) - ARRAY['current_version_id', 'updated_at']) THEN
                        RETURN NEW;
                    END IF;

                    IF NEW.status = 'draft'
                        AND (to_jsonb(NEW) - ARRAY['status', 'approved_by_user_id', 'approved_at', 'updated_at'])
                            = (to_jsonb(OLD) - ARRAY['status', 'approved_by_user_id', 'approved_at', 'updated_at']) THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'approved_estimate_is_immutable' USING ERRCODE = '23514';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_sealed_estimate_header()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.current_version_id IS NOT NULL AND OLD.status = 'approved' THEN
                    IF NEW.current_version_id IS DISTINCT FROM OLD.current_version_id
                        AND (to_jsonb(NEW) - ARRAY['current_version_id', 'updated_at'])
                            = (to_jsonb(OLD) - ARRAY['current_version_id', 'updated_at']) THEN
                        RETURN NEW;
                    END IF;

                    IF NEW.status = 'draft'
                        AND (to_jsonb(NEW) - ARRAY['status', 'approved_by_user_id', 'approved_at', 'updated_at'])
                            = (to_jsonb(OLD) - ARRAY['status', 'approved_by_user_id', 'approved_at', 'updated_at']) THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'approved_estimate_is_immutable' USING ERRCODE = '23514';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql
            SQL);
    }
};
