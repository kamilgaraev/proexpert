<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_invitations', function (Blueprint $table) {
            if (!Schema::hasColumn('user_invitations', 'user_id')) {
                $table->foreignId('user_id')->after('id')->constrained('users')->onDelete('cascade');
            }
            if (!Schema::hasColumn('user_invitations', 'plain_password')) {
                $table->string('plain_password')->after('email');
            }
            if (!Schema::hasColumn('user_invitations', 'status')) {
                $table->string('status')->default('sent')->after('plain_password');
            }
            if (!Schema::hasColumn('user_invitations', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        // Не удаляем колонки при откате, чтобы не потерять данные
    }
}; 