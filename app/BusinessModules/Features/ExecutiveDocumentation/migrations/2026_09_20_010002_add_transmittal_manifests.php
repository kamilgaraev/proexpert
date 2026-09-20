<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executive_document_transmittals', function (Blueprint $table): void {
            $table->string('manifest_hash', 64)->nullable();
            $table->string('status', 24)->default('sent');
            $table->unsignedBigInteger('previous_transmittal_id')->nullable();
            $table->foreignId('decision_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decision_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->jsonb('manifest')->nullable()->after('metadata');
            $table->string('operation_key', 128)->nullable()->after('manifest');
            $table->string('operation_hash', 64)->nullable()->after('operation_key');
            $table->unique(['organization_id', 'document_set_id', 'operation_key'], 'executive_transmittals_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('executive_document_transmittals', function (Blueprint $table): void {
            $table->dropUnique('executive_transmittals_operation_unique');
            $table->dropForeign(['decision_by']);
            $table->dropColumn(['manifest', 'operation_key', 'operation_hash', 'manifest_hash', 'status', 'previous_transmittal_id', 'decision_by', 'decision_at', 'decision_comment']);
        });
    }
};
