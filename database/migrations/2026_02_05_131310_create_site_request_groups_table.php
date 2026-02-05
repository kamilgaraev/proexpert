<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('site_request_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Creator
            $table->string('title')->nullable(); // Optional title for the group
            $table->string('status')->default('draft'); // Group status (draft, pending, etc.)
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('site_requests', function (Blueprint $table) {
            $table->foreignId('site_request_group_id')
                ->nullable()
                ->after('id')
                ->constrained('site_request_groups')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_requests', function (Blueprint $table) {
            $table->dropForeign(['site_request_group_id']);
            $table->dropColumn('site_request_group_id');
        });

        Schema::dropIfExists('site_request_groups');
    }
};
