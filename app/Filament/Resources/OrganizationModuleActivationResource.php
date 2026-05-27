<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationModuleActivationResource\Pages;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Services\Filament\ModuleAdminActionService;
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

class OrganizationModuleActivationResource extends Resource
{
    protected static ?string $model = OrganizationModuleActivation::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return NavigationGroups::platform();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('widgets.module_activations.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('widgets.module_activations.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('widgets.module_activations.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('widgets.module_activations.sections.overview'))
                    ->schema([
                        Infolists\Components\TextEntry::make('organization.name')
                            ->label(trans_message('widgets.modules.organization')),
                        Infolists\Components\TextEntry::make('module.name')
                            ->label(trans_message('widgets.modules.model_label')),
                        Infolists\Components\TextEntry::make('module.slug')
                            ->label(trans_message('widgets.modules.slug'))
                            ->copyable(),
                        Infolists\Components\TextEntry::make('status')
                            ->label(trans_message('widgets.module_activations.status'))
                            ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                            ->badge()
                            ->color(fn (?string $state): string => self::statusColor($state)),
                        Infolists\Components\TextEntry::make('is_bundled_with_plan')
                            ->label(trans_message('widgets.module_activations.source'))
                            ->formatStateUsing(fn (mixed $state): string => self::sourceLabel((bool) $state))
                            ->badge()
                            ->color(fn (mixed $state): string => (bool) $state ? 'info' : 'gray'),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.module_activations.sections.timeline'))
                    ->schema([
                        Infolists\Components\TextEntry::make('activated_at')
                            ->label(trans_message('widgets.module_activations.activated_at'))
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('expires_at')
                            ->label(trans_message('widgets.module_activations.expires_at'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('trial_ends_at')
                            ->label(trans_message('widgets.module_activations.trial_ends_at'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('next_billing_date')
                            ->label(trans_message('widgets.module_activations.next_billing_date'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('cancelled_at')
                            ->label(trans_message('widgets.module_activations.cancelled_at'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('cancellation_reason')
                            ->label(trans_message('widgets.module_activations.cancellation_reason'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['organization', 'module']))
            ->columns([
                Tables\Columns\TextColumn::make('organization.name')
                    ->label(trans_message('widgets.modules.organization'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('module.name')
                    ->label(trans_message('widgets.modules.model_label'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('module.slug')
                    ->label(trans_message('widgets.modules.slug'))
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(trans_message('widgets.module_activations.status'))
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('is_bundled_with_plan')
                    ->label(trans_message('widgets.module_activations.source'))
                    ->formatStateUsing(fn (mixed $state): string => self::sourceLabel((bool) $state))
                    ->badge()
                    ->color(fn (mixed $state): string => (bool) $state ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label(trans_message('widgets.module_activations.expires_at'))
                    ->dateTime()
                    ->placeholder(trans_message('widgets.common.empty_value'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('trial_ends_at')
                    ->label(trans_message('widgets.module_activations.trial_ends_at'))
                    ->dateTime()
                    ->placeholder(trans_message('widgets.common.empty_value'))
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_auto_renew_enabled')
                    ->label(trans_message('widgets.module_activations.auto_renew'))
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(trans_message('widgets.module_activations.status'))
                    ->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('organization_id')
                    ->label(trans_message('widgets.modules.organization'))
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('module_id')
                    ->label(trans_message('widgets.modules.model_label'))
                    ->options(fn (): array => Module::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\TernaryFilter::make('is_bundled_with_plan')
                    ->label(trans_message('widgets.module_activations.source')),
            ])
            ->actions([
                ViewAction::make(),
                self::enableAction(),
                self::disableAction(),
                self::startTrialAction(),
                self::extendAccessAction(),
                self::syncEntitlementsAction(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizationModuleActivations::route('/'),
            'view' => Pages\ViewOrganizationModuleActivation::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::MODULES_VIEW);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof OrganizationModuleActivation && self::canViewAny();
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

    private static function enableAction(): Action
    {
        return Action::make('enable')
            ->label(trans_message('filament_actions.module.enable.label'))
            ->icon('heroicon-o-play')
            ->color('success')
            ->schema([self::reasonField()])
            ->visible(fn (OrganizationModuleActivation $record): bool => self::isStandalone($record) && $record->status !== 'active' && self::canManageModules())
            ->action(function (array $data, OrganizationModuleActivation $record): void {
                self::runActivationAction($record, $data, 'enable');
            });
    }

    private static function disableAction(): Action
    {
        return Action::make('disable')
            ->label(trans_message('filament_actions.module.disable.label'))
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(trans_message('filament_actions.module.disable.heading'))
            ->schema([self::reasonField()])
            ->visible(fn (OrganizationModuleActivation $record): bool => self::isStandalone($record) && in_array($record->status, ['active', 'trial'], true) && self::canManageModules())
            ->action(function (array $data, OrganizationModuleActivation $record): void {
                self::runActivationAction($record, $data, 'disable');
            });
    }

    private static function startTrialAction(): Action
    {
        return Action::make('start_trial')
            ->label(trans_message('filament_actions.module.start_trial.label'))
            ->icon('heroicon-o-clock')
            ->color('info')
            ->schema([
                Forms\Components\TextInput::make('days')
                    ->label(trans_message('filament_actions.module.days'))
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                self::reasonField(),
            ])
            ->visible(fn (OrganizationModuleActivation $record): bool => self::isStandalone($record) && self::canManageModules())
            ->action(function (array $data, OrganizationModuleActivation $record): void {
                self::runActivationAction($record, $data, 'start_trial');
            });
    }

    private static function extendAccessAction(): Action
    {
        return Action::make('extend_access')
            ->label(trans_message('filament_actions.module.extend_access.label'))
            ->icon('heroicon-o-calendar-days')
            ->color('info')
            ->schema([
                Forms\Components\TextInput::make('days')
                    ->label(trans_message('filament_actions.module.days'))
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                self::reasonField(),
            ])
            ->visible(fn (OrganizationModuleActivation $record): bool => self::isStandalone($record) && self::canManageModules())
            ->action(function (array $data, OrganizationModuleActivation $record): void {
                self::runActivationAction($record, $data, 'extend_access');
            });
    }

    private static function syncEntitlementsAction(): Action
    {
        return Action::make('sync_entitlements')
            ->label(trans_message('filament_actions.module.sync_entitlements.label'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(trans_message('filament_actions.module.sync_entitlements.heading'))
            ->visible(fn (): bool => self::canManageModules())
            ->action(function (OrganizationModuleActivation $record): void {
                $actor = SystemAdminAccess::user();
                $organization = $record->organization;

                if ($actor === null || ! $organization instanceof Organization) {
                    return;
                }

                app(ModuleAdminActionService::class)->syncEntitlements($organization, $actor);
                Notification::make()->success()->title(trans_message('filament_actions.module.sync_entitlements.success'))->send();
            });
    }

    private static function reasonField(): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make('reason')
            ->label(trans_message('filament_actions.module.reason'))
            ->required()
            ->maxLength(500);
    }

    private static function runActivationAction(OrganizationModuleActivation $record, array $data, string $operation): void
    {
        $actor = SystemAdminAccess::user();

        if ($actor === null) {
            return;
        }

        $service = app(ModuleAdminActionService::class);
        $reason = (string) ($data['reason'] ?? '');

        match ($operation) {
            'enable' => $service->enable($record, $actor, $reason),
            'disable' => $service->disable($record, $actor, $reason),
            'start_trial' => $service->startTrial($record, $actor, max(1, (int) ($data['days'] ?? 1)), $reason),
            'extend_access' => $service->extendAccess($record, $actor, max(1, (int) ($data['days'] ?? 1)), $reason),
            default => null,
        };

        Notification::make()->success()->title(trans_message('filament_actions.module.operation_success'))->send();
    }

    private static function isStandalone(OrganizationModuleActivation $record): bool
    {
        return ! (bool) $record->is_bundled_with_plan;
    }

    private static function canManageModules(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::MODULES_MANAGE);
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
            'trial', 'pending' => 'warning',
            'suspended', 'expired' => 'danger',
            default => 'gray',
        };
    }

    private static function sourceLabel(bool $isBundled): string
    {
        return $isBundled
            ? trans_message('widgets.module_activations.source_plan')
            : trans_message('widgets.module_activations.source_manual');
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return [
            'active' => trans_message('widgets.module_activations.status_active'),
            'suspended' => trans_message('widgets.module_activations.status_suspended'),
            'expired' => trans_message('widgets.module_activations.status_expired'),
            'trial' => trans_message('widgets.module_activations.status_trial'),
            'pending' => trans_message('widgets.module_activations.status_pending'),
        ];
    }
}
