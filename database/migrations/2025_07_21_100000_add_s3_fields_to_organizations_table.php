<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('s3_bucket')->nullable()->unique()->after('description');
            $table->unsignedBigInteger('storage_used_mb')->default(0)->after('s3_bucket');
            $table->timestamp('storage_usage_synced_at')->nullable()->after('storage_used_mb');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['s3_bucket', 'storage_used_mb', 'storage_usage_synced_at']);
        });
    }
}; 