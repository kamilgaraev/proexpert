<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Support\TableEmptyState;
use App\Filament\Resources\SubscriptionPlanResource\Pages;
use App\Filament\Support\NavigationGroups;
use App\Models\SubscriptionPlan;
use App\Models\SystemAdmin;
use App\Policies\SystemAdmin\SubscriptionPlanResourcePolicy;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

use function trans_message;

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return NavigationGroups::billing();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('widgets.subscription_plans.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('widgets.subscription_plans.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('widgets.subscription_plans.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('widgets.subscription_plans.section_main'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(trans_message('widgets.subscription_plans.name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('slug')
                            ->label(trans_message('widgets.subscription_plans.slug'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('description')
                            ->label(trans_message('widgets.subscription_plans.description'))
                            ->maxLength(1000)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('price')
                            ->label(trans_message('widgets.subscription_plans.price'))
                            ->numeric()
                            ->prefix('₽')
                            ->required(),
                        Forms\Components\TextInput::make('currency')
                            ->label(trans_message('widgets.subscription_plans.currency'))
                            ->required()
                            ->maxLength(3),
                        Forms\Components\Select::make('billing_cycle')
                            ->label(trans_message('widgets.subscription_plans.period'))
                            ->options(self::billingCycleOptions())
                            ->required(),
                        Forms\Components\TextInput::make('duration_in_days')
                            ->label(trans_message('widgets.subscription_plans.duration_in_days'))
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('trial_days')
                            ->label(trans_message('widgets.subscription_plans.trial_days'))
                            ->numeric()
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label(trans_message('widgets.subscription_plans.is_active'))
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.subscription_plans.section_limits'))
                    ->schema([
                        Forms\Components\TextInput::make('max_users')
                            ->label(trans_message('widgets.subscription_plans.max_users'))
                            ->numeric(),
                        Forms\Components\TextInput::make('max_projects')
                            ->label(trans_message('widgets.subscription_plans.max_projects'))
                            ->numeric(),
                        Forms\Components\TextInput::make('max_storage_gb')
                            ->label(trans_message('widgets.subscription_plans.max_storage_gb'))
                            ->numeric(),
                        Forms\Components\TextInput::make('max_foremen')
                            ->label(trans_message('widgets.subscription_plans.max_foremen'))
                            ->numeric(),
                        Forms\Components\TextInput::make('max_contractor_invitations')
                            ->label(trans_message('widgets.subscription_plans.max_contractor_invitations'))
                            ->numeric(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('widgets.subscription_plans.section_main'))
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label(trans_message('widgets.subscription_plans.name')),
                        Infolists\Components\TextEntry::make('slug')
                            ->label(trans_message('widgets.subscription_plans.slug'))
                            ->copyable(),
                        Infolists\Components\TextEntry::make('price')
                            ->label(trans_message('widgets.subscription_plans.price'))
                            ->money('RUB'),
                        Infolists\Components\TextEntry::make('currency')
                            ->label(trans_message('widgets.subscription_plans.currency')),
                        Infolists\Components\TextEntry::make('billing_cycle')
                            ->label(trans_message('widgets.subscription_plans.period'))
                            ->formatStateUsing(fn (?string $state): string => self::billingCycleLabel($state)),
                        Infolists\Components\TextEntry::make('duration_in_days')
                            ->label(trans_message('widgets.subscription_plans.duration_in_days')),
                        Infolists\Components\TextEntry::make('trial_days')
                            ->label(trans_message('widgets.subscription_plans.trial_days')),
                        Infolists\Components\TextEntry::make('is_active')
                            ->label(trans_message('widgets.subscription_plans.is_active'))
                            ->formatStateUsing(fn (mixed $state): string => $state
                                ? trans_message('widgets.subscription_plans.status_active')
                                : trans_message('widgets.subscription_plans.status_inactive'))
                            ->badge()
                            ->color(fn (mixed $state): string => $state ? 'success' : 'gray'),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.subscription_plans.section_limits'))
                    ->schema([
                        Infolists\Components\TextEntry::make('max_users')
                            ->label(trans_message('widgets.subscription_plans.max_users'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('max_projects')
                            ->label(trans_message('widgets.subscription_plans.max_projects'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('max_storage_gb')
                            ->label(trans_message('widgets.subscription_plans.max_storage_gb'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('max_foremen')
                            ->label(trans_message('widgets.subscription_plans.max_foremen'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('max_contractor_invitations')
                            ->label(trans_message('widgets.subscription_plans.max_contractor_invitations'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'subscription_plans', 'heroicon-o-credit-card')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(trans_message('widgets.subscription_plans.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label(trans_message('widgets.subscription_plans.slug'))
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('price')
                    ->label(trans_message('widgets.subscription_plans.price'))
                    ->money('RUB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('billing_cycle')
                    ->label(trans_message('widgets.subscription_plans.period'))
                    ->formatStateUsing(fn (?string $state): string => self::billingCycleLabel($state))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(trans_message('widgets.subscription_plans.is_active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(trans_message('widgets.subscription_plans.is_active')),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'view' => Pages\ViewSubscriptionPlan::route('/{record}'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(SubscriptionPlanResourcePolicy::class)->viewAny($user);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null
            && $record instanceof SubscriptionPlan
            && app(SubscriptionPlanResourcePolicy::class)->view($user, $record);
    }

    public static function canCreate(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(SubscriptionPlanResourcePolicy::class)->create($user);
    }

    public static function canEdit(Model $record): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null
            && $record instanceof SubscriptionPlan
            && app(SubscriptionPlanResourcePolicy::class)->update($user, $record);
    }

    public static function canDelete(Model $record): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null
            && $record instanceof SubscriptionPlan
            && app(SubscriptionPlanResourcePolicy::class)->delete($user, $record);
    }

    public static function canDeleteAny(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(SubscriptionPlanResourcePolicy::class)->deleteAny($user);
    }

    protected static function getSystemAdmin(): ?SystemAdmin
    {
        $user = Auth::guard('system_admin')->user();

        return $user instanceof SystemAdmin ? $user : null;
    }

    private static function billingCycleLabel(?string $state): string
    {
        if ($state === null || $state === '') {
            return trans_message('widgets.common.empty_value');
        }

        return self::billingCycleOptions()[$state] ?? $state;
    }

    /**
     * @return array<string, string>
     */
    private static function billingCycleOptions(): array
    {
        return [
            'monthly' => trans_message('widgets.subscription_plans.billing_monthly'),
            'quarterly' => trans_message('widgets.subscription_plans.billing_quarterly'),
            'yearly' => trans_message('widgets.subscription_plans.billing_yearly'),
        ];
    }
}
