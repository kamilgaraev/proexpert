<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionPlanResource\Pages;
use App\Models\SubscriptionPlan;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-credit-card';

    protected static string | \UnitEnum | null $navigationGroup = 'System';
    
    protected static bool $shouldSkipAuthorization = true;

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
                Forms\Components\Section::make(__('widgets.subscription_plans.section_main'))
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
                        Forms\Components\Select::make('duration_in_days')
                            ->label(__('widgets.subscription_plans.period'))
                            ->options([
                                30 => 'Ежемесячно',
                                365 => 'Ежегодно',
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
                    ->sortable(),
                Tables\Columns\TextColumn::make('duration_in_days')
                    ->label(__('widgets.subscription_plans.period'))
                    ->formatStateUsing(fn ($state) => match ((string)$state) {
                        '30' => 'Ежемесячно',
                        '365' => 'Ежегодно',
                        default => $state . ' дн.',
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
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
}
