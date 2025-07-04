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
    public function invite(string $email, string $name, User $creator): UserInvitation
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
            'role_slugs'           => [],
            'token'                => Str::random(64),
            'expires_at'           => now()->addDays(7),
            'plain_password'       => $plainPassword,
            'status'               => 'pending',
            'sent_at'              => now(),
        ]);

        // send mail
        Mail::to($email)->send(new UserInvitationMail($email, $plainPassword));

        return $invitation;
    }

    public function resend(UserInvitation $invitation): void
    {
        Mail::to($invitation->email)->send(new UserInvitationMail($invitation->email, $invitation->plain_password));
        $invitation->update(['sent_at' => now()]);
    }
} 