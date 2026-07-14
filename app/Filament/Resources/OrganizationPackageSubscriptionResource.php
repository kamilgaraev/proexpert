<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationPackageSubscriptionResource\Pages;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Filament\Support\TableEmptyState;
use App\Models\OrganizationPackageSubscription;
use Filament\Actions\ViewAction;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

use function trans_message;

class OrganizationPackageSubscriptionResource extends Resource
{
    protected static ?string $model = OrganizationPackageSubscription::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::platform();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('widgets.package_subscriptions.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('widgets.package_subscriptions.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('widgets.package_subscriptions.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('widgets.package_subscriptions.sections.overview'))
                    ->schema([
                        Infolists\Components\TextEntry::make('organization.name')
                            ->label(trans_message('widgets.modules.organization')),
                        Infolists\Components\TextEntry::make('package_slug')
                            ->label(trans_message('widgets.package_subscriptions.package')),
                        Infolists\Components\TextEntry::make('status')
                            ->label(trans_message('widgets.module_activations.status'))
                            ->badge(),
                        Infolists\Components\TextEntry::make('access_source')
                            ->label(trans_message('widgets.module_activations.source'))
                            ->badge(),
                        Infolists\Components\TextEntry::make('commercialAccount.id')
                            ->label(trans_message('widgets.package_subscriptions.subscription'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('price_paid')
                            ->label(trans_message('widgets.package_subscriptions.price_paid'))
                            ->money('RUB'),
                        Infolists\Components\TextEntry::make('current_period_start_at')
                            ->label(trans_message('widgets.module_activations.activated_at'))
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('current_period_end_at')
                            ->label(trans_message('widgets.module_activations.expires_at'))
                            ->dateTime()
                            ->placeholder(trans_message('widgets.common.empty_value')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'package_subscriptions', 'heroicon-o-archive-box')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['organization', 'commercialAccount']))
            ->columns([
                Tables\Columns\TextColumn::make('organization.name')
                    ->label(trans_message('widgets.modules.organization'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('package_slug')
                    ->label(trans_message('widgets.package_subscriptions.package'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(trans_message('widgets.module_activations.status'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('access_source')
                    ->label(trans_message('widgets.module_activations.source'))
                    ->badge(),
                Tables\Columns\TextColumn::make('price_paid')
                    ->label(trans_message('widgets.package_subscriptions.price_paid'))
                    ->money('RUB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_period_end_at')
                    ->label(trans_message('widgets.module_activations.expires_at'))
                    ->dateTime()
                    ->placeholder(trans_message('widgets.common.empty_value'))
                    ->sortable(),
            ])
            ->filters([])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizationPackageSubscriptions::route('/'),
            'view' => Pages\ViewOrganizationPackageSubscription::route('/{record}'),
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
        return $record instanceof OrganizationPackageSubscription && self::canViewAny();
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
}
