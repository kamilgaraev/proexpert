<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SystemAdminResource\Pages;
use App\Models\SystemAdmin;
use App\Policies\SystemAdmin\SystemAdminResourcePolicy;
use App\Services\Security\SystemAdminRoleService;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SystemAdminResource extends Resource
{
    protected static ?string $model = SystemAdmin::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return 'Администраторы';
    }

    public static function getModelLabel(): string
    {
        return 'администратор';
    }

    public static function getPluralModelLabel(): string
    {
        return 'администраторы';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Профиль')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Имя')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                    ])
                    ->columns(2),
                Section::make('Доступ')
                    ->schema([
                        Forms\Components\Select::make('role')
                            ->label('Роль')
                            ->options(fn (?SystemAdmin $record): array => self::getRoleOptions($record))
                            ->required()
                            ->searchable(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true),
                        Placeholder::make('role_permissions')
                            ->label('Описание роли')
                            ->content(fn (?SystemAdmin $record, Get $get): string => self::getRoleDescription($get('role') ?: $record?->getRoleSlug()))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Безопасность')
                    ->schema([
                        Forms\Components\TextInput::make('password')
                            ->label('Пароль')
                            ->password()
                            ->revealable(filament()->arePasswordsRevealable())
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->minLength(8)
                            ->same('passwordConfirmation'),
                        Forms\Components\TextInput::make('passwordConfirmation')
                            ->label('Подтверждение пароля')
                            ->password()
                            ->revealable(filament()->arePasswordsRevealable())
                            ->required(fn (Get $get, string $operation): bool => $operation === 'create' || filled($get('password')))
                            ->dehydrated(false),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->label('Роль')
                    ->formatStateUsing(fn (?string $state): string => self::resolveRoleLabel($state))
                    ->badge(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Обновлен')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSystemAdmins::route('/'),
            'create' => Pages\CreateSystemAdmin::route('/create'),
            'edit' => Pages\EditSystemAdmin::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(SystemAdminResourcePolicy::class)->viewAny($user);
    }

    public static function canCreate(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(SystemAdminResourcePolicy::class)->create($user);
    }

    public static function canEdit(Model $record): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null
            && $record instanceof SystemAdmin
            && app(SystemAdminResourcePolicy::class)->update($user, $record);
    }

    public static function canDelete(Model $record): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null
            && $record instanceof SystemAdmin
            && app(SystemAdminResourcePolicy::class)->delete($user, $record);
    }

    public static function canDeleteAny(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(SystemAdminResourcePolicy::class)->deleteAny($user);
    }

    protected static function getSystemAdmin(): ?SystemAdmin
    {
        $user = Auth::guard('system_admin')->user();

        return $user instanceof SystemAdmin ? $user : null;
    }

    protected static function getRoleOptions(?SystemAdmin $record = null): array
    {
        $roleService = app(SystemAdminRoleService::class);
        $currentAdmin = self::getSystemAdmin();

        return $roleService
            ->getAllRoles()
            ->filter(function (array $role) use ($currentAdmin, $record, $roleService): bool {
                if ($currentAdmin === null || !isset($role['slug'])) {
                    return false;
                }

                if ($currentAdmin->isSuperAdmin()) {
                    return true;
                }

                if ($record instanceof SystemAdmin && $record->getRoleSlug() === $role['slug']) {
                    return true;
                }

                return $roleService->canManageRole($currentAdmin, $role['slug']);
            })
            ->mapWithKeys(fn (array $role): array => [$role['slug'] => $role['name'] ?? $role['slug']])
            ->all();
    }

    protected static function getRoleDescription(?string $roleSlug): string
    {
        if (!$roleSlug) {
            return 'Выберите роль';
        }

        $role = app(SystemAdminRoleService::class)->getRole($roleSlug);

        return is_array($role) ? (string) ($role['description'] ?? 'Описание роли не задано') : 'Роль не найдена';
    }

    protected static function resolveRoleLabel(?string $roleSlug): string
    {
        if (!$roleSlug) {
            return 'Не назначена';
        }

        $role = app(SystemAdminRoleService::class)->getRole($roleSlug);

        return is_array($role) ? (string) ($role['name'] ?? $roleSlug) : $roleSlug;
    }
}
