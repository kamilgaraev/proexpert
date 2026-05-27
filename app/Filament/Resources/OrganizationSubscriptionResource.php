<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationSubscriptionResource\Pages;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionPlan;
use App\Services\Filament\SubscriptionAdminActionService;
use Filament\Actions\Action;
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

use function trans_message;

class OrganizationSubscriptionResource extends Resource
{
    protected static ?string $model = OrganizationSubscription::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return NavigationGroups::billing();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('widgets.subscriptions.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('widgets.subscriptions.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('widgets.subscriptions.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('widgets.subscriptions.sections.overview'))
                    ->schema([
                        Infolists\Components\TextEntry::make('organization.name')
                            ->label(trans_message('widgets.subscriptions.organization')),
                        Infolists\Components\TextEntry::make('plan.name')
                            ->label(trans_message('widgets.subscriptions.plan')),
                        Infolists\Components\TextEntry::make('status')
                            ->label(trans_message('widgets.subscriptions.status'))
                            ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                            ->badge()
                            ->color(fn (?string $state): string => self::statusColor($state)),
                        Infolists\Components\TextEntry::make('is_auto_payment_enabled')
                            ->label(trans_message('widgets.subscriptions.auto_payment'))
                            ->formatStateUsing(fn (mixed $state): string => $state
                                ? trans_message('widgets.common.yes')
                                : trans_message('widgets.common.no'))
                            ->badge()
                            ->color(fn (mixed $state): string => $state ? 'success' : 'gray'),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.subscriptions.sections.timeline'))
                    ->schema([
                        Infolists\Components\TextEntry::make('trial_ends_at')
                            ->label(trans_message('widgets.subscriptions.trial_ends_at'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('starts_at')
                            ->label(trans_message('widgets.subscriptions.starts_at'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('ends_at')
                            ->label(trans_message('widgets.subscriptions.ends_at'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('next_billing_at')
                            ->label(trans_message('widgets.subscriptions.next_billing_at'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('canceled_at')
                            ->label(trans_message('widgets.subscriptions.canceled_at'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.common.empty_value')),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.subscriptions.sections.provider'))
                    ->schema([
                        Infolists\Components\TextEntry::make('payment_gateway_subscription_id')
                            ->label(trans_message('widgets.subscriptions.provider_subscription_id'))
                            ->formatStateUsing(fn (?string $state): string => self::maskExternalId($state))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('payment_gateway_customer_id')
                            ->label(trans_message('widgets.subscriptions.provider_customer_id'))
                            ->formatStateUsing(fn (?string $state): string => self::maskExternalId($state))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['organization', 'plan']))
            ->columns([
                Tables\Columns\TextColumn::make('organization.name')
                    ->label(trans_message('widgets.subscriptions.organization'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('plan.name')
                    ->label(trans_message('widgets.subscriptions.plan'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(trans_message('widgets.subscriptions.status'))
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('ends_at')
                    ->label(trans_message('widgets.subscriptions.ends_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('next_billing_at')
                    ->label(trans_message('widgets.subscriptions.next_billing_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_auto_payment_enabled')
                    ->label(trans_message('widgets.subscriptions.auto_payment'))
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('canceled_at')
                    ->label(trans_message('widgets.subscriptions.canceled_at'))
                    ->dateTime()
                    ->placeholder(trans_message('widgets.common.empty_value'))
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(trans_message('widgets.subscriptions.status'))
                    ->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('subscription_plan_id')
                    ->label(trans_message('widgets.subscriptions.plan'))
                    ->options(fn (): array => SubscriptionPlan::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('organization_id')
                    ->label(trans_message('widgets.subscriptions.organization'))
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\TernaryFilter::make('canceled_at')
                    ->label(trans_message('widgets.subscriptions.canceled_at'))
                    ->nullable()
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('canceled_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('canceled_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->actions([
                ViewAction::make(),
                Action::make('cancel_at_period_end')
                    ->label(trans_message('filament_actions.subscription.cancel_at_period_end.label'))
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(trans_message('filament_actions.subscription.cancel_at_period_end.heading'))
                    ->modalDescription(trans_message('filament_actions.subscription.cancel_at_period_end.description'))
                    ->modalSubmitActionLabel(trans_message('filament_actions.subscription.cancel_at_period_end.confirm'))
                    ->schema([
                        Forms\Components\Textarea::make('reason')
                            ->label(trans_message('filament_actions.subscription.reason'))
                            ->required()
                            ->maxLength(500),
                    ])
                    ->visible(fn (OrganizationSubscription $record): bool => $record->canceled_at === null && self::canManageSubscriptions())
                    ->action(function (array $data, OrganizationSubscription $record): void {
                        self::cancelAtPeriodEnd($record, $data);
                    }),
                Action::make('reactivate')
                    ->label(trans_message('filament_actions.subscription.reactivate.label'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(trans_message('filament_actions.subscription.reactivate.heading'))
                    ->modalDescription(trans_message('filament_actions.subscription.reactivate.description'))
                    ->modalSubmitActionLabel(trans_message('filament_actions.subscription.reactivate.confirm'))
                    ->visible(fn (OrganizationSubscription $record): bool => $record->canceled_at !== null && self::canManageSubscriptions())
                    ->action(function (OrganizationSubscription $record): void {
                        self::reactivateSubscription($record);
                    }),
                Action::make('grant_manual_extension')
                    ->label(trans_message('filament_actions.subscription.extension.grant_label'))
                    ->icon('heroicon-o-calendar-days')
                    ->color('info')
                    ->modalHeading(trans_message('filament_actions.subscription.extension.grant_heading'))
                    ->schema([
                        Forms\Components\TextInput::make('days')
                            ->label(trans_message('filament_actions.subscription.extension.days'))
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Forms\Components\Textarea::make('reason')
                            ->label(trans_message('filament_actions.subscription.reason'))
                            ->required()
                            ->maxLength(500),
                    ])
                    ->visible(fn (): bool => self::canManageSubscriptions())
                    ->action(function (array $data, OrganizationSubscription $record): void {
                        self::grantManualExtension($record, $data);
                    }),
                Action::make('revoke_manual_extension')
                    ->label(trans_message('filament_actions.subscription.extension.revoke_label'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(trans_message('filament_actions.subscription.extension.revoke_heading'))
                    ->schema([
                        Forms\Components\Textarea::make('reason')
                            ->label(trans_message('filament_actions.subscription.reason'))
                            ->required()
                            ->maxLength(500),
                    ])
                    ->visible(fn (OrganizationSubscription $record): bool => self::hasManualExtension($record) && self::canManageSubscriptions())
                    ->action(function (array $data, OrganizationSubscription $record): void {
                        self::revokeManualExtension($record, $data);
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizationSubscriptions::route('/'),
            'view' => Pages\ViewOrganizationSubscription::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::SUBSCRIPTIONS_VIEW);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof OrganizationSubscription && self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    private static function cancelAtPeriodEnd(OrganizationSubscription $subscription, array $data): void
    {
        $actor = SystemAdminAccess::user();

        if ($actor === null) {
            return;
        }

        app(SubscriptionAdminActionService::class)->cancelAtPeriodEnd(
            subscription: $subscription,
            actor: $actor,
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : null,
        );

        Notification::make()->success()->title(trans_message('filament_actions.subscription.cancel_at_period_end.success'))->send();
    }

    private static function reactivateSubscription(OrganizationSubscription $subscription): void
    {
        $actor = SystemAdminAccess::user();

        if ($actor === null) {
            return;
        }

        app(SubscriptionAdminActionService::class)->reactivate($subscription, $actor);

        Notification::make()->success()->title(trans_message('filament_actions.subscription.reactivate.success'))->send();
    }

    private static function grantManualExtension(OrganizationSubscription $subscription, array $data): void
    {
        $actor = SystemAdminAccess::user();

        if ($actor === null) {
            return;
        }

        app(SubscriptionAdminActionService::class)->grantManualExtension(
            subscription: $subscription,
            actor: $actor,
            days: max(1, (int) ($data['days'] ?? 1)),
            reason: (string) ($data['reason'] ?? ''),
        );

        Notification::make()->success()->title(trans_message('filament_actions.subscription.extension.grant_success'))->send();
    }

    private static function revokeManualExtension(OrganizationSubscription $subscription, array $data): void
    {
        $actor = SystemAdminAccess::user();

        if ($actor === null) {
            return;
        }

        app(SubscriptionAdminActionService::class)->revokeManualExtension(
            subscription: $subscription,
            actor: $actor,
            reason: (string) ($data['reason'] ?? ''),
        );

        Notification::make()->success()->title(trans_message('filament_actions.subscription.extension.revoke_success'))->send();
    }

    private static function canManageSubscriptions(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::SUBSCRIPTIONS_MANAGE);
    }

    private static function hasManualExtension(OrganizationSubscription $subscription): bool
    {
        $config = is_array($subscription->enterprise_constructor_config) ? $subscription->enterprise_constructor_config : [];

        return isset($config['manual_extension']) && is_array($config['manual_extension']);
    }

    private static function maskExternalId(?string $value): string
    {
        if ($value === null || $value === '') {
            return trans_message('widgets.common.empty_value');
        }

        return strlen($value) <= 8 ? $value : substr($value, 0, 4) . '...' . substr($value, -4);
    }

    private static function statusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return trans_message('widgets.common.empty_value');
        }

        return self::statusOptions()[$status] ?? $status;
    }

    private static function statusColor(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'trial', 'pending_payment' => 'warning',
            'canceled', 'expired', 'failed' => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return [
            'active' => trans_message('widgets.subscriptions.status_active'),
            'trial' => trans_message('widgets.subscriptions.status_trial'),
            'pending_payment' => trans_message('widgets.subscriptions.status_pending_payment'),
            'canceled' => trans_message('widgets.subscriptions.status_canceled'),
            'expired' => trans_message('widgets.subscriptions.status_expired'),
            'failed' => trans_message('widgets.subscriptions.status_failed'),
        ];
    }
}
