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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('position')->nullable()->after('phone');
            $table->string('avatar_path')->nullable()->after('position');
            $table->boolean('is_active')->default(true)->after('avatar_path');
            $table->foreignId('current_organization_id')->nullable()->after('is_active')->constrained('organizations')->onDelete('set null');
            $table->string('user_type')->default('user')->after('current_organization_id'); // system_admin, organization_admin, foreman, accountant, etc.
            $table->json('settings')->nullable()->after('user_type');
            $table->timestamp('last_login_at')->nullable()->after('settings');
            $table->string('last_login_ip')->nullable()->after('last_login_at');
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_organization_id']);
            $table->dropColumn([
                'phone',
                'position',
                'avatar_path',
                'is_active',
                'current_organization_id',
                'user_type',
                'settings',
                'last_login_at',
                'last_login_ip',
            ]);
            $table->dropSoftDeletes();
        });
    }
};
