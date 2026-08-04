<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.report_publication_promote(
                text, text, jsonb, jsonb, text, text, text, text, text, text,
                text, text, text, text, text, text, text, text, text, text, timestamptz
            );
            DROP FUNCTION IF EXISTS public.report_publication_disable(text, text, text);
            DROP FUNCTION IF EXISTS public.report_publication_configure_feature(
                text, text, text, text, jsonb, jsonb
            );
            DROP FUNCTION IF EXISTS public.report_publication_mark_outbox_delivered(text, timestamptz);
            SQL);
    }

    public function down(): void
    {
    }
};
