<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('users')->where(function ($query): void {
                $query->where(function ($demo): void {
                    $demo->whereJsonContains('settings->demo_account', true)
                        ->where(function ($identity): void {
                            $identity->where('email', 'like', 'demo.%@prohelper.test')
                                ->orWhere('email', 'like', 'demo.%@most.test');
                        });
                })->orWhere(function ($load): void {
                    $load->whereJsonContains('settings->load_test', true)
                        ->where('email', 'like', 'loadtest-org%@prohelper.test');
                });
            })->select('id')->orderBy('id')->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        'password' => Hash::make(bin2hex(random_bytes(32))),
                        'remember_token' => Str::random(60),
                        'is_active' => false,
                        'updated_at' => now(),
                    ]);
                }
                if (Schema::hasTable('user_auth_sessions')) {
                    DB::table('user_auth_sessions')->whereIn('user_id', $users->pluck('id'))->update([
                        'status' => 'revoked',
                        'revoked_at' => now(),
                        'revoked_reason' => 'seeded_credentials_retired',
                        'updated_at' => now(),
                    ]);
                }
            });
        });
    }

    public function down(): void
    {
    }
};
