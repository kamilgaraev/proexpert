<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionPlanResource\Pages;
use App\Models\SubscriptionPlan;
use App\Models\SystemAdmin;
use App\Policies\SystemAdmin\SubscriptionPlanResourcePolicy;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-credit-card';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    public static function getNavigationLabel(): string
    {
        return __('widgets.subscription_plans.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('widgets.subscription_plans.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('widgets.subscription_plans.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('widgets.subscription_plans.section_main'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('widgets.subscription_plans.name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('slug')
                            ->label(__('widgets.subscription_plans.slug'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('price')
                            ->label(__('widgets.subscription_plans.price'))
                            ->numeric()
                            ->prefix('₽')
                            ->required(),
                        Forms\Components\Select::make('billing_cycle')
                            ->label(__('widgets.subscription_plans.period'))
                            ->options([
                                'monthly' => 'Ежемесячно',
                                'quarterly' => 'Ежеквартально',
                                'yearly' => 'Ежегодно',
                            ])
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label(__('widgets.subscription_plans.is_active'))
                            ->default(true),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('widgets.subscription_plans.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price')
                    ->label(__('widgets.subscription_plans.price'))
                    ->money('RUB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('billing_cycle')
                    ->label(__('widgets.subscription_plans.period'))
                    ->formatStateUsing(fn ($state) => match ((string)$state) {
                        'monthly' => 'Ежемесячно',
                        'quarterly' => 'Ежеквартально',
                        'yearly' => 'Ежегодно',
                        default => $state,
                    })
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('widgets.subscription_plans.is_active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(SubscriptionPlanResourcePolicy::class)->viewAny($user);
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
}
