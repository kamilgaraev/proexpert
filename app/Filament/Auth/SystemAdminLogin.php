<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Http\Middleware\EnsureSystemAdminSessionIsFresh;
use App\Models\SystemAdmin;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SystemAdminLogin extends Login
{
    public function authenticate(): ?LoginResponse
    {
        $this->data['remember'] = false;

        $response = parent::authenticate();

        if ($response === null) {
            return null;
        }

        $user = Filament::auth()->user();

        if ($user instanceof SystemAdmin) {
            $user->forceFill([
                'remember_token' => Str::random(60),
            ])->save();
        }

        session()->regenerateToken();
        session()->put(
            EnsureSystemAdminSessionIsFresh::SESSION_GENERATION_KEY,
            EnsureSystemAdminSessionIsFresh::currentSessionGeneration(),
        );

        return $response;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
            ]);
    }
}
