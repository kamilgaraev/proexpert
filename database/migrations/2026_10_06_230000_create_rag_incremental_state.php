<?php

declare(strict_types=1);

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_rag_coverage_states', function (Blueprint $table): void {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->primary('organization_id');
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedBigInteger('index_version')->default(0);
            $table->unsignedBigInteger('acknowledged_index_version')->default(0);
            $table->unsignedBigInteger('published_revision')->nullable();
            $table->uuid('active_generation')->nullable();
            $table->jsonb('snapshot')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON public.ai_rag_coverage_states FOR EACH STATEMENT EXECUTE FUNCTION public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()');
        DB::statement('ALTER TABLE public.ai_rag_coverage_states ENABLE ALWAYS TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);

        Schema::create('ai_rag_embedding_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('source_key', 100);
            $table->string('profile_key', 64);
            $table->string('content_hash', 64);
            $table->text('embedding');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'source_key', 'profile_key', 'content_hash'], 'ai_rag_embedding_checkpoint_unique');
            $table->index(['expires_at', 'id'], 'ai_rag_embedding_checkpoint_retention_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_rag_embedding_checkpoints');
        Schema::dropIfExists('ai_rag_coverage_states');
    }
};
