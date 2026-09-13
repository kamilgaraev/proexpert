<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_source_links', function (Blueprint $table): void {
            $table->string('status', 20)->default('active');
            $table->text('ended_reason')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('replacement_link_id')->nullable()->constrained('design_source_links')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('design_source_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replacement_link_id');
            $table->dropConstrainedForeignId('ended_by');
            $table->dropColumn(['status', 'ended_reason', 'ended_at']);
        });
    }
};
