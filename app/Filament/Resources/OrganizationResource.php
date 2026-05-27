<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationResource\Pages;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Filament\Support\TableEmptyState;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SystemAdmin;
use App\Policies\SystemAdmin\OrganizationResourcePolicy;
use App\Services\Filament\OrganizationAdminActionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::organizations();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('widgets.organizations.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('widgets.organizations.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('widgets.organizations.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('widgets.organizations.sections.main'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(trans_message('widgets.organizations.name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('legal_name')
                            ->label(trans_message('widgets.organizations.legal_name'))
                            ->maxLength(255),
                        Forms\Components\TextInput::make('tax_number')
                            ->label(trans_message('widgets.organizations.inn'))
                            ->maxLength(20),
                        Forms\Components\TextInput::make('registration_number')
                            ->label(trans_message('widgets.organizations.ogrn'))
                            ->maxLength(20),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.organizations.sections.contacts'))
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->label(trans_message('widgets.organizations.email'))
                            ->email()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->label(trans_message('widgets.organizations.phone'))
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('address')
                            ->label(trans_message('widgets.organizations.address'))
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.organizations.sections.status'))
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label(trans_message('widgets.organizations.is_active'))
                            ->default(true),
                        Forms\Components\Select::make('verification_status')
                            ->label(trans_message('widgets.organizations.verification_status'))
                            ->options(self::verificationStatusOptions())
                            ->default('pending'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('widgets.organizations.sections.details'))
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label(trans_message('widgets.organizations.name'))
                            ->copyable(),
                        Infolists\Components\TextEntry::make('legal_name')
                            ->label(trans_message('widgets.organizations.legal_name'))
                            ->placeholder(trans_message('widgets.organizations.empty_value')),
                        Infolists\Components\TextEntry::make('tax_number')
                            ->label(trans_message('widgets.organizations.inn'))
                            ->copyable()
                            ->placeholder(trans_message('widgets.organizations.empty_value')),
                        Infolists\Components\TextEntry::make('registration_number')
                            ->label(trans_message('widgets.organizations.ogrn'))
                            ->copyable()
                            ->placeholder(trans_message('widgets.organizations.empty_value')),
                        Infolists\Components\TextEntry::make('email')
                            ->label(trans_message('widgets.organizations.email'))
                            ->placeholder(trans_message('widgets.organizations.empty_value')),
                        Infolists\Components\TextEntry::make('phone')
                            ->label(trans_message('widgets.organizations.phone'))
                            ->placeholder(trans_message('widgets.organizations.empty_value')),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.organizations.sections.status'))
                    ->schema([
                        Infolists\Components\TextEntry::make('is_active')
                            ->label(trans_message('widgets.organizations.platform_status'))
                            ->formatStateUsing(fn (mixed $state): string => $state
                                ? trans_message('widgets.organizations.status_active')
                                : trans_message('widgets.organizations.status_suspended'))
                            ->badge()
                            ->color(fn (mixed $state): string => $state ? 'success' : 'danger'),
                        Infolists\Components\TextEntry::make('verification_status')
                            ->label(trans_message('widgets.organizations.verification_status'))
                            ->formatStateUsing(fn (?string $state): string => self::verificationStatusLabel($state))
                            ->badge()
                            ->color(fn (?string $state): string => self::verificationStatusColor($state)),
                        Infolists\Components\TextEntry::make('subscription_state')
                            ->label(trans_message('widgets.organizations.subscription_status'))
                            ->getStateUsing(fn (Organization $record): string => self::subscriptionStateLabel(self::resolveSubscriptionState($record)))
                            ->badge()
                            ->color(fn (Organization $record): string => self::subscriptionStateColor(self::resolveSubscriptionState($record))),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label(trans_message('widgets.organizations.created_at'))
                            ->dateTime(),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.organizations.sections.subscription'))
                    ->schema([
                        Infolists\Components\TextEntry::make('currentSubscription.plan.name')
                            ->label(trans_message('widgets.organizations.subscription_plan'))
                            ->placeholder(trans_message('widgets.organizations.no_subscription')),
                        Infolists\Components\TextEntry::make('currentSubscription.ends_at')
                            ->label(trans_message('widgets.organizations.subscription_ends_at'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.organizations.empty_value')),
                        Infolists\Components\TextEntry::make('subscription_expires_at')
                            ->label(trans_message('widgets.organizations.subscription_expires_at'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.organizations.empty_value')),
                        Infolists\Components\TextEntry::make('storage_used_mb')
                            ->label(trans_message('widgets.organizations.storage_usage'))
                            ->formatStateUsing(fn (mixed $state): string => self::formatStorageUsage($state)),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.organizations.sections.metrics'))
                    ->schema([
                        Infolists\Components\TextEntry::make('users_count')
                            ->label(trans_message('widgets.organizations.users_count'))
                            ->getStateUsing(fn (Organization $record): int => $record->users()->count()),
                        Infolists\Components\TextEntry::make('projects_count')
                            ->label(trans_message('widgets.organizations.projects_count'))
                            ->getStateUsing(fn (Organization $record): int => $record->projects()->count()),
                        Infolists\Components\TextEntry::make('contracts_count')
                            ->label(trans_message('widgets.organizations.contracts_count'))
                            ->getStateUsing(fn (Organization $record): int => $record->contracts()->count()),
                        Infolists\Components\TextEntry::make('storage_usage_synced_at')
                            ->label(trans_message('widgets.organizations.storage_synced_at'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.organizations.empty_value')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'organizations', 'heroicon-o-building-office-2')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['currentSubscription.plan'])
                ->withCount(['users', 'projects', 'contracts']))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(trans_message('widgets.organizations.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('legal_name')
                    ->label(trans_message('widgets.organizations.legal_name'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tax_number')
                    ->label(trans_message('widgets.organizations.inn'))
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('platform_status')
                    ->label(trans_message('widgets.organizations.platform_status'))
                    ->getStateUsing(fn (Organization $record): string => $record->is_active
                        ? trans_message('widgets.organizations.status_active')
                        : trans_message('widgets.organizations.status_suspended'))
                    ->badge()
                    ->color(fn (Organization $record): string => $record->is_active ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('verification_status')
                    ->label(trans_message('widgets.organizations.verification_status'))
                    ->formatStateUsing(fn (?string $state): string => self::verificationStatusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::verificationStatusColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('currentSubscription.plan.name')
                    ->label(trans_message('widgets.organizations.subscription_plan'))
                    ->placeholder(trans_message('widgets.organizations.no_subscription'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('subscription_state')
                    ->label(trans_message('widgets.organizations.subscription_status'))
                    ->getStateUsing(fn (Organization $record): string => self::subscriptionStateLabel(self::resolveSubscriptionState($record)))
                    ->badge()
                    ->color(fn (Organization $record): string => self::subscriptionStateColor(self::resolveSubscriptionState($record))),
                Tables\Columns\TextColumn::make('users_count')
                    ->label(trans_message('widgets.organizations.users_count'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('projects_count')
                    ->label(trans_message('widgets.organizations.projects_count'))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('storage_used_mb')
                    ->label(trans_message('widgets.organizations.storage_usage'))
                    ->formatStateUsing(fn (mixed $state): string => self::formatStorageUsage($state))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(trans_message('widgets.organizations.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(trans_message('widgets.organizations.platform_status')),
                Tables\Filters\SelectFilter::make('verification_status')
                    ->label(trans_message('widgets.organizations.verification_status'))
                    ->options(self::verificationStatusOptions()),
                Tables\Filters\Filter::make('subscription_state')
                    ->label(trans_message('widgets.organizations.subscription_status'))
                    ->schema([
                        Forms\Components\Select::make('state')
                            ->label(trans_message('widgets.organizations.subscription_status'))
                            ->options(self::subscriptionStateOptions()),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applySubscriptionStateFilter($query, $data['state'] ?? null)),
                Tables\Filters\Filter::make('created_at')
                    ->label(trans_message('widgets.organizations.created_period'))
                    ->schema([
                        Forms\Components\DatePicker::make('created_from')
                            ->label(trans_message('widgets.organizations.created_from')),
                        Forms\Components\DatePicker::make('created_until')
                            ->label(trans_message('widgets.organizations.created_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyCreatedPeriodFilter($query, $data)),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    Action::make('suspend')
                        ->label(trans_message('filament_actions.organization.suspend.label'))
                        ->icon('heroicon-o-lock-closed')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading(trans_message('filament_actions.organization.suspend.heading'))
                        ->modalDescription(trans_message('filament_actions.organization.suspend.description'))
                        ->modalSubmitActionLabel(trans_message('filament_actions.organization.suspend.confirm'))
                        ->visible(fn (Organization $record): bool => self::canSuspendOrganization($record))
                        ->action(function (Organization $record): void {
                            self::suspendOrganization($record);
                        }),
                    Action::make('reactivate')
                        ->label(trans_message('filament_actions.organization.reactivate.label'))
                        ->icon('heroicon-o-lock-open')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(trans_message('filament_actions.organization.reactivate.heading'))
                        ->modalDescription(trans_message('filament_actions.organization.reactivate.description'))
                        ->modalSubmitActionLabel(trans_message('filament_actions.organization.reactivate.confirm'))
                        ->visible(fn (Organization $record): bool => self::canReactivateOrganization($record))
                        ->action(function (Organization $record): void {
                            self::reactivateOrganization($record);
                        }),
                ]),
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
            'index' => Pages\ListOrganizations::route('/'),
            'create' => Pages\CreateOrganization::route('/create'),
            'view' => Pages\ViewOrganization::route('/{record}'),
            'edit' => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(OrganizationResourcePolicy::class)->viewAny($user);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null
            && $record instanceof Organization
            && app(OrganizationResourcePolicy::class)->view($user, $record);
    }

    public static function canCreate(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(OrganizationResourcePolicy::class)->create($user);
    }

    public static function canEdit(Model $record): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null
            && $record instanceof Organization
            && app(OrganizationResourcePolicy::class)->update($user, $record);
    }

    public static function canDelete(Model $record): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null
            && $record instanceof Organization
            && app(OrganizationResourcePolicy::class)->delete($user, $record);
    }

    public static function canDeleteAny(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(OrganizationResourcePolicy::class)->deleteAny($user);
    }

    protected static function getSystemAdmin(): ?SystemAdmin
    {
        $user = Auth::guard('system_admin')->user();

        return $user instanceof SystemAdmin ? $user : null;
    }

    private static function canSuspendOrganization(Organization $organization): bool
    {
        return $organization->is_active && SystemAdminAccess::can(FilamentPermission::ORGANIZATIONS_SUSPEND);
    }

    private static function canReactivateOrganization(Organization $organization): bool
    {
        return ! $organization->is_active && SystemAdminAccess::can(FilamentPermission::ORGANIZATIONS_REACTIVATE);
    }

    private static function suspendOrganization(Organization $organization): void
    {
        $actor = SystemAdminAccess::user();

        if (! $actor instanceof SystemAdmin) {
            return;
        }

        app(OrganizationAdminActionService::class)->suspend($organization, $actor, 'manual_panel_action');

        Notification::make()
            ->success()
            ->title(trans_message('filament_actions.organization.suspend.success'))
            ->send();
    }

    private static function reactivateOrganization(Organization $organization): void
    {
        $actor = SystemAdminAccess::user();

        if (! $actor instanceof SystemAdmin) {
            return;
        }

        app(OrganizationAdminActionService::class)->reactivate($organization, $actor);

        Notification::make()
            ->success()
            ->title(trans_message('filament_actions.organization.reactivate.success'))
            ->send();
    }

    private static function applySubscriptionStateFilter(Builder $query, mixed $state): Builder
    {
        if (! is_string($state) || $state === '') {
            return $query;
        }

        return match ($state) {
            'active' => $query->where(function (Builder $query): void {
                $query->where('subscription_expires_at', '>', now())
                    ->orWhereHas('subscriptions', function (Builder $query): void {
                        $query->whereIn('status', ['active', 'trial'])
                            ->where(function (Builder $query): void {
                                $query->whereNull('ends_at')
                                    ->orWhere('ends_at', '>', now());
                            });
                    });
            }),
            'expired' => $query->where(function (Builder $query): void {
                $query->where('subscription_expires_at', '<=', now())
                    ->orWhereHas('subscriptions', function (Builder $query): void {
                        $query->whereNotNull('ends_at')
                            ->where('ends_at', '<=', now());
                    });
            }),
            'none' => $query->whereNull('subscription_expires_at')->whereDoesntHave('subscriptions'),
            default => $query->whereHas('subscriptions', fn (Builder $query): Builder => $query->where('status', $state)),
        };
    }

    /**
     * @param  array<string, mixed>  $data
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

    private static function resolveSubscriptionState(Organization $organization): string
    {
        $subscription = $organization->currentSubscription;

        if ($subscription instanceof OrganizationSubscription) {
            if (
                in_array($subscription->status, ['active', 'trial'], true)
                && ($subscription->ends_at === null || $subscription->ends_at->isFuture())
            ) {
                return 'active';
            }

            if ($subscription->ends_at !== null && $subscription->ends_at->isPast()) {
                return 'expired';
            }

            return $subscription->status;
        }

        if ($organization->subscription_expires_at !== null) {
            return $organization->subscription_expires_at->isPast() ? 'expired' : 'active';
        }

        return 'none';
    }

    private static function subscriptionStateLabel(string $state): string
    {
        return self::subscriptionStateOptions()[$state] ?? $state;
    }

    private static function subscriptionStateColor(string $state): string
    {
        return match ($state) {
            'active' => 'success',
            'expired', 'canceled', 'failed' => 'danger',
            'pending_payment' => 'warning',
            'none' => 'gray',
            default => 'info',
        };
    }

    private static function verificationStatusLabel(?string $state): string
    {
        if ($state === null || $state === '') {
            return trans_message('widgets.organizations.verification_pending');
        }

        return self::verificationStatusOptions()[$state] ?? $state;
    }

    private static function verificationStatusColor(?string $state): string
    {
        return match ($state) {
            'verified' => 'success',
            'partially_verified', 'needs_review' => 'warning',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    private static function formatStorageUsage(mixed $state): string
    {
        $megabytes = is_numeric($state) ? (float) $state : 0.0;

        if ($megabytes >= 1024.0) {
            return number_format($megabytes / 1024, 1, '.', ' ').' ГБ';
        }

        return number_format($megabytes, 0, '.', ' ').' МБ';
    }

    /**
     * @return array<string, string>
     */
    private static function verificationStatusOptions(): array
    {
        return [
            'pending' => trans_message('widgets.organizations.verification_pending'),
            'verified' => trans_message('widgets.organizations.verification_verified'),
            'partially_verified' => trans_message('widgets.organizations.verification_partially_verified'),
            'needs_review' => trans_message('widgets.organizations.verification_needs_review'),
            'failed' => trans_message('widgets.organizations.verification_failed'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function subscriptionStateOptions(): array
    {
        return [
            'active' => trans_message('widgets.organizations.subscription_active'),
            'expired' => trans_message('widgets.organizations.subscription_expired'),
            'pending_payment' => trans_message('widgets.organizations.subscription_pending_payment'),
            'canceled' => trans_message('widgets.organizations.subscription_canceled'),
            'none' => trans_message('widgets.organizations.no_subscription'),
        ];
    }
}
