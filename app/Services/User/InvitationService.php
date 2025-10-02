<?php

namespace App\Services\User;

use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationService
{
    public function invite(string $email, string $name, User $creator, array $roleSlugs = []): UserInvitation
    {
        // Already existing user?
        $user = User::where('email', $email)->first();
        if (!$user) {
            $plainPassword = Str::random(10);
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($plainPassword),
            ]);
        } else {
            $plainPassword = Str::random(10);
            $user->password = Hash::make($plainPassword);
            $user->save();
        }

        // Attach to creator organization
        if ($creator->current_organization_id) {
            $user->organizations()->syncWithoutDetaching([$creator->current_organization_id]);
        }

        $invitation = UserInvitation::create([
            'organization_id'      => $creator->current_organization_id ?? optional($creator->organizations()->first())->id,
            'invited_by_user_id'   => $creator->id,
            'user_id'              => $user->id,
            'email'                => $email,
            'name'                 => $name,
            'role_slugs'           => $roleSlugs,
            'token'                => Str::random(64),
            'expires_at'           => now()->addDays(7),
            'plain_password'       => $plainPassword,
            'status'               => 'pending',
            'sent_at'              => now(),
        ]);

        // Определяем ссылку для входа в зависимости от ролей
        $loginUrl = 'https://prohelper.pro/login';
        if (in_array('foreman', $roleSlugs, true)) {
            $loginUrl = 'https://disk.yandex.ru/d/EUIo_ZBxzhLyjw';
        } elseif (array_intersect($roleSlugs, [
            'organization_admin', 'admin', 'accountant', 'web_admin'
        ])) {
            $loginUrl = 'https://admin.prohelper.pro/login';
        }

        // send mail
        Mail::to($email)->send(new UserInvitationMail($email, $plainPassword, $loginUrl));

        return $invitation;
    }

    public function resend(UserInvitation $invitation): void
    {
        $roleSlugs = $invitation->role_slugs ?? [];

        $loginUrl = 'https://prohelper.pro/login';
        if (in_array('foreman', $roleSlugs, true)) {
            $loginUrl = 'https://disk.yandex.ru/d/EUIo_ZBxzhLyjw';
        } elseif (array_intersect($roleSlugs, [
            'organization_admin', 'admin', 'accountant', 'web_admin'
        ])) {
            $loginUrl = 'https://admin.prohelper.pro/login';
        }

        Mail::to($invitation->email)->send(new UserInvitationMail($invitation->email, $invitation->plain_password, $loginUrl));
        $invitation->update(['sent_at' => now()]);
    }

    public function getInvitationStats(int $organizationId): array
    {
        $total = UserInvitation::where('organization_id', $organizationId)->count();
        $pending = UserInvitation::where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->count();
        $accepted = UserInvitation::where('organization_id', $organizationId)
            ->where('status', 'accepted')
            ->count();
        $expired = UserInvitation::where('organization_id', $organizationId)
            ->where('status', 'expired')
            ->count();

        return [
            'total' => $total,
            'pending' => $pending,
            'accepted' => $accepted,
            'expired' => $expired,
            'acceptance_rate' => $total > 0 ? round(($accepted / $total) * 100, 1) : 0,
        ];
    }
} 