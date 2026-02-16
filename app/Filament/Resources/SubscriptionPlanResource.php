<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionPlanResource\Pages;
use App\Models\SubscriptionPlan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Schema;

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-credit-card';

    protected static string | \UnitEnum | null $navigationGroup = 'System';
    
    protected static ?int $navigationSort = 3;
    
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
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
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

                Forms\Components\Section::make(__('widgets.subscription_plans.section_features'))
                    ->schema([
                        Forms\Components\Repeater::make('features')
                            ->label(__('widgets.subscription_plans.features'))
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('widgets.subscription_plans.feature_name'))
                                    ->required(),
                                Forms\Components\TextInput::make('value')
                                    ->label(__('widgets.subscription_plans.feature_value')),
                            ])
                            ->columnSpanFull(),
                    ]),
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
                Tables\Columns\TextColumn::make('duration_in_days')
                    ->label(__('widgets.subscription_plans.period'))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
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
