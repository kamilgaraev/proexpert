<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class PasswordResetService
{
    public function __construct(private readonly AuthSessionRevocationService $sessions)
    {
    }

    public function reset(array $payload): ?User
    {
        return DB::transaction(function () use ($payload): ?User {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [Str::lower((string) $payload['email'])])
                ->lockForUpdate()
                ->first();

            if (! $user instanceof User) {
                return null;
            }

            $table = $this->tokenTable();
            $tokenRecord = DB::table($table)
                ->where('email', $user->getEmailForPasswordReset())
                ->lockForUpdate()
                ->first();

            if ($tokenRecord === null
                || $this->tokenExpired((string) $tokenRecord->created_at)
                || ! Hash::check((string) $payload['token'], (string) $tokenRecord->token)
            ) {
                return null;
            }

            DB::table($table)
                ->where('email', $user->getEmailForPasswordReset())
                ->delete();

            $user->forceFill([
                'password' => Hash::make((string) $payload['password']),
                'remember_token' => Str::random(60),
            ])->save();

            $this->sessions->revokeAllForUser($user, 'password_reset');

            return $user;
        });
    }

    private function tokenExpired(string $createdAt): bool
    {
        $minutes = max(1, (int) config('auth.passwords.users.expire', 60));

        return Carbon::parse($createdAt)->addMinutes($minutes)->isPast();
    }

    private function tokenTable(): string
    {
        $table = config('auth.passwords.users.table');

        if (! is_string($table) || $table === '') {
            throw new \LogicException('Password reset token table is not configured.');
        }

        return $table;
    }
}
