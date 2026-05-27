<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Support\TableEmptyState;
use App\Enums\ModuleDevelopmentStatus;
use App\Filament\Resources\ModuleResource\Pages;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Models\Module;
use App\Models\Organization;
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

class ModuleResource extends Resource
{
    protected static ?string $model = Module::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return NavigationGroups::platform();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('widgets.modules.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('widgets.modules.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('widgets.modules.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('widgets.modules.sections.overview'))
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label(trans_message('widgets.modules.name')),
                        Infolists\Components\TextEntry::make('slug')
                            ->label(trans_message('widgets.modules.slug'))
                            ->copyable(),
                        Infolists\Components\TextEntry::make('category')
                            ->label(trans_message('widgets.modules.category')),
                        Infolists\Components\TextEntry::make('type')
                            ->label(trans_message('widgets.modules.type'))
                            ->formatStateUsing(fn (?string $state): string => self::typeLabel($state)),
                        Infolists\Components\TextEntry::make('billing_model')
                            ->label(trans_message('widgets.modules.billing_model'))
                            ->formatStateUsing(fn (?string $state): string => self::billingModelLabel($state)),
                        Infolists\Components\TextEntry::make('development_status')
                            ->label(trans_message('widgets.modules.development_status'))
                            ->formatStateUsing(fn (mixed $state): string => self::developmentStatusLabel($state))
                            ->badge(),
                        Infolists\Components\TextEntry::make('description')
                            ->label(trans_message('widgets.modules.description'))
                            ->placeholder(trans_message('widgets.common.empty_value'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.modules.sections.entitlements'))
                    ->schema([
                        Infolists\Components\TextEntry::make('dependencies')
                            ->label(trans_message('widgets.modules.dependencies'))
                            ->formatStateUsing(fn (mixed $state): string => self::formatList($state))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('conflicts')
                            ->label(trans_message('widgets.modules.conflicts'))
                            ->formatStateUsing(fn (mixed $state): string => self::formatList($state))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('permissions')
                            ->label(trans_message('widgets.modules.permissions'))
                            ->formatStateUsing(fn (mixed $state): string => self::formatList($state))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'modules', 'heroicon-o-squares-2x2')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('activeActivations'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(trans_message('widgets.modules.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label(trans_message('widgets.modules.slug'))
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('category')
                    ->label(trans_message('widgets.modules.category'))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('billing_model')
                    ->label(trans_message('widgets.modules.billing_model'))
                    ->formatStateUsing(fn (?string $state): string => self::billingModelLabel($state))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('development_status')
                    ->label(trans_message('widgets.modules.development_status'))
                    ->formatStateUsing(fn (mixed $state): string => self::developmentStatusLabel($state))
                    ->badge()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(trans_message('widgets.modules.is_active'))
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('can_deactivate')
                    ->label(trans_message('widgets.modules.can_deactivate'))
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('active_activations_count')
                    ->label(trans_message('widgets.modules.active_activations_count'))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(trans_message('widgets.modules.type'))
                    ->options(self::typeOptions()),
                Tables\Filters\SelectFilter::make('billing_model')
                    ->label(trans_message('widgets.modules.billing_model'))
                    ->options(self::billingModelOptions()),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(trans_message('widgets.modules.is_active')),
            ])
            ->actions([
                ViewAction::make(),
                Action::make('enable_for_organization')
                    ->label(trans_message('filament_actions.module.enable_for_organization.label'))
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->modalHeading(trans_message('filament_actions.module.enable_for_organization.heading'))
                    ->schema([
                        Forms\Components\Select::make('organization_id')
                            ->label(trans_message('widgets.modules.organization'))
                            ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        Forms\Components\Toggle::make('start_trial')
                            ->label(trans_message('filament_actions.module.start_trial.label')),
                        Forms\Components\TextInput::make('trial_days')
                            ->label(trans_message('filament_actions.module.days'))
                            ->numeric()
                            ->minValue(1),
                        Forms\Components\Textarea::make('reason')
                            ->label(trans_message('filament_actions.module.reason'))
                            ->required()
                            ->maxLength(500),
                    ])
                    ->visible(fn (): bool => self::canManageModules())
                    ->action(function (array $data, Module $record): void {
                        self::enableModuleForOrganization($record, $data);
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('display_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListModules::route('/'),
            'view' => Pages\ViewModule::route('/{record}'),
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
        return $record instanceof Module && self::canViewAny();
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

    private static function enableModuleForOrganization(Module $module, array $data): void
    {
        $actor = SystemAdminAccess::user();
        $organization = Organization::query()->find((int) ($data['organization_id'] ?? 0));

        if ($actor === null || ! $organization instanceof Organization) {
            return;
        }

        $trialDays = (bool) ($data['start_trial'] ?? false)
            ? max(1, (int) ($data['trial_days'] ?? 14))
            : null;

        app(ModuleAdminActionService::class)->enableForOrganization(
            organization: $organization,
            module: $module,
            actor: $actor,
            reason: (string) ($data['reason'] ?? ''),
            trialDays: $trialDays,
        );

        Notification::make()->success()->title(trans_message('filament_actions.module.enable_for_organization.success'))->send();
    }

    private static function canManageModules(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::MODULES_MANAGE);
    }

    private static function typeLabel(?string $state): string
    {
        if ($state === null || $state === '') {
            return trans_message('widgets.common.empty_value');
        }

        return self::typeOptions()[$state] ?? $state;
    }

    private static function billingModelLabel(?string $state): string
    {
        if ($state === null || $state === '') {
            return trans_message('widgets.common.empty_value');
        }

        return self::billingModelOptions()[$state] ?? $state;
    }

    private static function developmentStatusLabel(mixed $state): string
    {
        $value = $state instanceof ModuleDevelopmentStatus ? $state->value : (is_string($state) ? $state : null);

        if ($value === null || $value === '') {
            return trans_message('widgets.common.empty_value');
        }

        return [
            'stable' => trans_message('widgets.modules.development_stable'),
            'beta' => trans_message('widgets.modules.development_beta'),
            'experimental' => trans_message('widgets.modules.development_experimental'),
            'deprecated' => trans_message('widgets.modules.development_deprecated'),
        ][$value] ?? $value;
    }

    private static function formatList(mixed $state): string
    {
        if (! is_array($state) || $state === []) {
            return trans_message('widgets.common.empty_value');
        }

        return implode(', ', array_map('strval', $state));
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        return [
            'core' => trans_message('widgets.modules.type_core'),
            'feature' => trans_message('widgets.modules.type_feature'),
            'addon' => trans_message('widgets.modules.type_addon'),
            'service' => trans_message('widgets.modules.type_service'),
            'extension' => trans_message('widgets.modules.type_extension'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function billingModelOptions(): array
    {
        return [
            'free' => trans_message('widgets.modules.billing_free'),
            'subscription' => trans_message('widgets.modules.billing_subscription'),
            'one_time' => trans_message('widgets.modules.billing_one_time'),
            'usage_based' => trans_message('widgets.modules.billing_usage_based'),
            'freemium' => trans_message('widgets.modules.billing_freemium'),
        ];
    }
}
