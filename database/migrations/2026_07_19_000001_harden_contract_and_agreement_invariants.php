<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplementary_agreements', static function (Blueprint $table): void {
            $table->timestampTz('applied_at')->nullable();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('application_key')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('supplementary_agreements', static function (Blueprint $table): void {
            $table->dropUnique(['application_key']);
            $table->dropConstrainedForeignId('applied_by_user_id');
            $table->dropColumn(['applied_at', 'application_key']);
        });
    }
};
