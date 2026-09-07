<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('user_invitations')
                    ->whereColumn('accepted_by_user_id', 'users.id')
                    ->whereColumn('user_invitations.email', 'users.email')
                    ->whereColumn('accepted_at', 'users.created_at')
                    ->where('status', 'accepted');
            })
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
    }
};
