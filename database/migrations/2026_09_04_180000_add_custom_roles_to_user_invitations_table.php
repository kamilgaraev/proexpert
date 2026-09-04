<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_invitations', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('plain_password')->nullable()->change();
            $table->jsonb('custom_roles')->default('[]')->after('role_slugs');
        });
    }

    public function down(): void
    {
        Schema::table('user_invitations', function (Blueprint $table): void {
            $table->dropColumn('custom_roles');
        });
    }
};
