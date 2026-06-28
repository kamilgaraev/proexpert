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
        if (DB::connection()->getDriverName() !== 'pgsql') {
            Schema::table('knowledge_articles', function (Blueprint $table): void {
                $table->text('search_vector')->nullable();
            });

            return;
        }

        DB::statement('ALTER TABLE knowledge_articles ADD COLUMN search_vector tsvector');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION knowledge_articles_refresh_search_vector()
            RETURNS trigger AS $$
            BEGIN
                NEW.content_plain_text := NULLIF(
                    trim(regexp_replace(COALESCE(NEW.content_plain_text, regexp_replace(COALESCE(NEW.content, ''), '<[^>]*>', ' ', 'g')), '\s+', ' ', 'g')),
                    ''
                );

                NEW.search_vector :=
                    setweight(to_tsvector('russian', COALESCE(NEW.title, '')), 'A') ||
                    setweight(to_tsvector('russian', COALESCE(NEW.excerpt, '')), 'B') ||
                    setweight(to_tsvector('russian', COALESCE(NEW.content_plain_text, '')), 'C') ||
                    setweight(to_tsvector('simple', COALESCE(array_to_string(ARRAY(SELECT jsonb_array_elements_text(COALESCE(NEW.tags, '[]'::jsonb))), ' '), '')), 'D');

                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER knowledge_articles_refresh_search_vector_trigger
            BEFORE INSERT OR UPDATE OF title, excerpt, content, content_plain_text, tags
            ON knowledge_articles
            FOR EACH ROW
            EXECUTE FUNCTION knowledge_articles_refresh_search_vector()
        SQL);

        DB::statement("UPDATE knowledge_articles SET content_plain_text = NULLIF(trim(regexp_replace(COALESCE(content, ''), '<[^>]*>', ' ', 'g')), '')");

        DB::statement('CREATE INDEX knowledge_articles_search_vector_gin_idx ON knowledge_articles USING GIN (search_vector)');
        DB::statement('CREATE INDEX knowledge_articles_audiences_gin_idx ON knowledge_articles USING GIN (audiences)');
        DB::statement('CREATE INDEX knowledge_articles_surfaces_gin_idx ON knowledge_articles USING GIN (surfaces)');
        DB::statement('CREATE INDEX knowledge_articles_module_slugs_gin_idx ON knowledge_articles USING GIN (module_slugs)');
        DB::statement('CREATE INDEX knowledge_articles_permission_keys_gin_idx ON knowledge_articles USING GIN (permission_keys)');
        DB::statement('CREATE INDEX knowledge_articles_context_keys_gin_idx ON knowledge_articles USING GIN (context_keys)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            Schema::table('knowledge_articles', function (Blueprint $table): void {
                $table->dropColumn('search_vector');
            });

            return;
        }

        DB::statement('DROP INDEX IF EXISTS knowledge_articles_context_keys_gin_idx');
        DB::statement('DROP INDEX IF EXISTS knowledge_articles_permission_keys_gin_idx');
        DB::statement('DROP INDEX IF EXISTS knowledge_articles_module_slugs_gin_idx');
        DB::statement('DROP INDEX IF EXISTS knowledge_articles_surfaces_gin_idx');
        DB::statement('DROP INDEX IF EXISTS knowledge_articles_audiences_gin_idx');
        DB::statement('DROP INDEX IF EXISTS knowledge_articles_search_vector_gin_idx');
        DB::statement('DROP TRIGGER IF EXISTS knowledge_articles_refresh_search_vector_trigger ON knowledge_articles');
        DB::statement('DROP FUNCTION IF EXISTS knowledge_articles_refresh_search_vector()');
        DB::statement('ALTER TABLE knowledge_articles DROP COLUMN IF EXISTS search_vector');
    }
};
