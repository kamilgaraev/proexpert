<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Policies\SystemAdmin\UserResourcePolicy;
use App\Services\Filament\UserAdminActionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

use function trans_message;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return NavigationGroups::users();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('widgets.users.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('widgets.users.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('widgets.users.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('widgets.users.sections.profile'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(trans_message('widgets.users.name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->label(trans_message('widgets.users.email'))
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->label(trans_message('widgets.users.phone'))
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('position')
                            ->label(trans_message('widgets.users.position'))
                            ->maxLength(255),
                        Forms\Components\Select::make('current_organization_id')
                            ->label(trans_message('widgets.users.current_organization'))
                            ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('widgets.users.sections.profile'))
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label(trans_message('widgets.users.name'))
                            ->copyable(),
                        Infolists\Components\TextEntry::make('email')
                            ->label(trans_message('widgets.users.email'))
                            ->copyable(),
                        Infolists\Components\TextEntry::make('phone')
                            ->label(trans_message('widgets.users.phone'))
                            ->placeholder(trans_message('widgets.users.empty_value')),
                        Infolists\Components\TextEntry::make('position')
                            ->label(trans_message('widgets.users.position'))
                            ->placeholder(trans_message('widgets.users.empty_value')),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.users.sections.status'))
                    ->schema([
                        Infolists\Components\TextEntry::make('is_active')
                            ->label(trans_message('widgets.users.status'))
                            ->formatStateUsing(fn (mixed $state): string => $state
                                ? trans_message('widgets.users.status_active')
                                : trans_message('widgets.users.status_blocked'))
                            ->badge()
                            ->color(fn (mixed $state): string => $state ? 'success' : 'danger'),
                        Infolists\Components\TextEntry::make('email_verified_at')
                            ->label(trans_message('widgets.users.email_verification'))
                            ->formatStateUsing(fn (mixed $state): string => $state
                                ? trans_message('widgets.users.email_verified')
                                : trans_message('widgets.users.email_not_verified'))
                            ->badge()
                            ->color(fn (mixed $state): string => $state ? 'success' : 'warning'),
                        Infolists\Components\TextEntry::make('last_login_at')
                            ->label(trans_message('widgets.users.last_login_at'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.users.empty_value')),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label(trans_message('widgets.users.created_at'))
                            ->dateTime(),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.users.sections.access'))
                    ->schema([
                        Infolists\Components\TextEntry::make('currentOrganization.name')
                            ->label(trans_message('widgets.users.current_organization'))
                            ->placeholder(trans_message('widgets.users.empty_value')),
                        Infolists\Components\TextEntry::make('organizations_list')
                            ->label(trans_message('widgets.users.organizations'))
                            ->getStateUsing(fn (User $record): string => self::formatOrganizations($record)),
                        Infolists\Components\TextEntry::make('active_roles')
                            ->label(trans_message('widgets.users.roles'))
                            ->getStateUsing(fn (User $record): string => self::formatActiveRoles($record)),
                        Infolists\Components\TextEntry::make('organization_memberships_count')
                            ->label(trans_message('widgets.users.organizations_count'))
                            ->getStateUsing(fn (User $record): int => $record->organizations()->count()),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['currentOrganization'])
                ->withCount(['organizations', 'roleAssignments']))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(trans_message('widgets.users.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(trans_message('widgets.users.email'))
                    ->searchable()
                    ->copyable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('currentOrganization.name')
                    ->label(trans_message('widgets.users.current_organization'))
                    ->placeholder(trans_message('widgets.users.empty_value'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('is_active')
                    ->label(trans_message('widgets.users.status'))
                    ->formatStateUsing(fn (mixed $state): string => $state
                        ? trans_message('widgets.users.status_active')
                        : trans_message('widgets.users.status_blocked'))
                    ->badge()
                    ->color(fn (mixed $state): string => $state ? 'success' : 'danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('email_verified_at')
                    ->label(trans_message('widgets.users.email_verification'))
                    ->getStateUsing(fn (User $record): string => $record->email_verified_at
                        ? trans_message('widgets.users.email_verified')
                        : trans_message('widgets.users.email_not_verified'))
                    ->badge()
                    ->color(fn (User $record): string => $record->email_verified_at ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('organizations_count')
                    ->label(trans_message('widgets.users.organizations_count'))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->label(trans_message('widgets.users.last_login_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(trans_message('widgets.users.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(trans_message('widgets.users.status')),
                Tables\Filters\TernaryFilter::make('email_verified_at')
                    ->label(trans_message('widgets.users.email_verification'))
                    ->nullable()
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('email_verified_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('email_verified_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\SelectFilter::make('current_organization_id')
                    ->label(trans_message('widgets.users.current_organization'))
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\Filter::make('created_at')
                    ->label(trans_message('widgets.users.created_period'))
                    ->schema([
                        Forms\Components\DatePicker::make('created_from')
                            ->label(trans_message('widgets.users.created_from')),
                        Forms\Components\DatePicker::make('created_until')
                            ->label(trans_message('widgets.users.created_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyCreatedPeriodFilter($query, $data)),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('block')
                    ->label(trans_message('filament_actions.user.block.label'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(trans_message('filament_actions.user.block.heading'))
                    ->modalDescription(trans_message('filament_actions.user.block.description'))
                    ->modalSubmitActionLabel(trans_message('filament_actions.user.block.confirm'))
                    ->visible(fn (User $record): bool => self::canBlockUser($record))
                    ->action(function (User $record): void {
                        self::blockUser($record);
                    }),
                Action::make('unblock')
                    ->label(trans_message('filament_actions.user.unblock.label'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(trans_message('filament_actions.user.unblock.heading'))
                    ->modalDescription(trans_message('filament_actions.user.unblock.description'))
                    ->modalSubmitActionLabel(trans_message('filament_actions.user.unblock.confirm'))
                    ->visible(fn (User $record): bool => self::canUnblockUser($record))
                    ->action(function (User $record): void {
                        self::unblockUser($record);
                    }),
                Action::make('mark_email_verified')
                    ->label(trans_message('filament_actions.user.mark_email_verified.label'))
                    ->icon('heroicon-o-envelope-open')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(trans_message('filament_actions.user.mark_email_verified.heading'))
                    ->modalDescription(trans_message('filament_actions.user.mark_email_verified.description'))
                    ->modalSubmitActionLabel(trans_message('filament_actions.user.mark_email_verified.confirm'))
                    ->visible(fn (User $record): bool => self::canMarkEmailVerified($record))
                    ->action(function (User $record): void {
                        self::markEmailVerified($record);
                    }),
                Action::make('send_password_reset')
                    ->label(trans_message('filament_actions.user.send_password_reset.label'))
                    ->icon('heroicon-o-key')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(trans_message('filament_actions.user.send_password_reset.heading'))
                    ->modalDescription(trans_message('filament_actions.user.send_password_reset.description'))
                    ->modalSubmitActionLabel(trans_message('filament_actions.user.send_password_reset.confirm'))
                    ->visible(fn (): bool => SystemAdminAccess::can(FilamentPermission::USERS_SEND_PASSWORD_RESET))
                    ->action(function (User $record): void {
                        self::sendPasswordReset($record);
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(UserResourcePolicy::class)->viewAny($user);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null
            && $record instanceof User
            && app(UserResourcePolicy::class)->view($user, $record);
    }

    public static function canCreate(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(UserResourcePolicy::class)->create($user);
    }

    public static function canEdit(Model $record): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null
            && $record instanceof User
            && app(UserResourcePolicy::class)->update($user, $record);
    }

    public static function canDelete(Model $record): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null
            && $record instanceof User
            && app(UserResourcePolicy::class)->delete($user, $record);
    }

    public static function canDeleteAny(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(UserResourcePolicy::class)->deleteAny($user);
    }

    protected static function getSystemAdmin(): ?SystemAdmin
    {
        $user = Auth::guard('system_admin')->user();

        return $user instanceof SystemAdmin ? $user : null;
    }

    private static function canBlockUser(User $user): bool
    {
        return $user->is_active && SystemAdminAccess::can(FilamentPermission::USERS_BLOCK);
    }

    private static function canUnblockUser(User $user): bool
    {
        return ! $user->is_active && SystemAdminAccess::can(FilamentPermission::USERS_BLOCK);
    }

    private static function canMarkEmailVerified(User $user): bool
    {
        return $user->email_verified_at === null && SystemAdminAccess::can(FilamentPermission::USERS_VERIFY_EMAIL);
    }

    private static function blockUser(User $user): void
    {
        $actor = SystemAdminAccess::user();

        if (! $actor instanceof SystemAdmin) {
            return;
        }

        app(UserAdminActionService::class)->block($user, $actor);

        Notification::make()
            ->success()
            ->title(trans_message('filament_actions.user.block.success'))
            ->send();
    }

    private static function unblockUser(User $user): void
    {
        $actor = SystemAdminAccess::user();

        if (! $actor instanceof SystemAdmin) {
            return;
        }

        app(UserAdminActionService::class)->unblock($user, $actor);

        Notification::make()
            ->success()
            ->title(trans_message('filament_actions.user.unblock.success'))
            ->send();
    }

    private static function markEmailVerified(User $user): void
    {
        $actor = SystemAdminAccess::user();

        if (! $actor instanceof SystemAdmin) {
            return;
        }

        app(UserAdminActionService::class)->markEmailVerified($user, $actor);

        Notification::make()
            ->success()
            ->title(trans_message('filament_actions.user.mark_email_verified.success'))
            ->send();
    }

    private static function sendPasswordReset(User $user): void
    {
        $actor = SystemAdminAccess::user();

        if (! $actor instanceof SystemAdmin) {
            return;
        }

        app(UserAdminActionService::class)->sendPasswordReset($user, $actor);

        Notification::make()
            ->success()
            ->title(trans_message('filament_actions.user.send_password_reset.success'))
            ->send();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function applyCreatedPeriodFilter(Builder $query, array $data): Builder
    {
        $from = $data['created_from'] ?? null;
        $until = $data['created_until'] ?? null;

        if (is_string($from) && $from !== '') {
            $query->whereDate('created_at', '>=', $from);
        }

        if (is_string($until) && $until !== '') {
            $query->whereDate('created_at', '<=', $until);
        }

        return $query;
    }

    private static function formatOrganizations(User $user): string
    {
        $organizations = $user->organizations()
            ->orderBy('name')
            ->pluck('name')
            ->filter()
            ->all();

        return $organizations === []
            ? trans_message('widgets.users.empty_value')
            : implode(', ', array_map(static fn (mixed $name): string => (string) $name, $organizations));
    }

    private static function formatActiveRoles(User $user): string
    {
        $roles = $user->roleAssignments()
            ->where('is_active', true)
            ->orderBy('role_slug')
            ->pluck('role_slug')
            ->filter()
            ->all();

        return $roles === []
            ? trans_message('widgets.users.empty_value')
            : implode(', ', array_map(static fn (mixed $role): string => (string) $role, $roles));
    }
}
