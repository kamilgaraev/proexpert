<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\SystemAdmin;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditSystemAdminProfile extends EditProfile
{
    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof SystemAdmin
            && $user->hasSystemPermission('system_admin.profile.view');
    }

    public static function getLabel(): string
    {
        return 'Мой профиль';
    }

    public function save(): void
    {
        abort_unless($this->canUpdateProfile(), 403);

        parent::save();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var SystemAdmin $user */
        $user = $this->getUser();

        $data['role_label'] = $user->getRoleName();
        $data['status_label'] = $user->isActive() ? 'Активен' : 'Отключен';
        $data['permissions_preview'] = implode(', ', $user->getPermissionLabels());

        return $data;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Настройки пользователя')
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                    ])
                    ->columns(2),
                Section::make('Роль и доступ')
                    ->schema([
                        Placeholder::make('role_label')
                            ->label('Роль')
                            ->content(fn (): string => (string) ($this->data['role_label'] ?? 'Не назначена')),
                        Placeholder::make('status_label')
                            ->label('Статус')
                            ->content(fn (): string => (string) ($this->data['status_label'] ?? 'Неизвестно')),
                        Placeholder::make('permissions_preview')
                            ->label('Права')
                            ->content(fn (): string => (string) ($this->data['permissions_preview'] ?? 'Нет прав'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Безопасность')
                    ->schema([
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                        $this->getCurrentPasswordFormComponent(),
                    ])
                    ->columns(2),
            ]);
    }

    protected function getSaveFormAction(): \Filament\Actions\Action
    {
        return parent::getSaveFormAction()
            ->visible(fn (): bool => $this->canUpdateProfile());
    }

    protected function canUpdateProfile(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof SystemAdmin
            && $user->hasSystemPermission('system_admin.profile.update');
    }
}
