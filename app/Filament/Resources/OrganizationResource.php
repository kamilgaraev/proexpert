<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationResource\Pages;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Policies\SystemAdmin\OrganizationResourcePolicy;
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

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    public static function getNavigationLabel(): string
    {
        return __('widgets.organizations.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('widgets.organizations.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('widgets.organizations.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основная информация')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('widgets.organizations.name') ?? 'Название')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('tax_number')
                            ->label(__('widgets.organizations.inn') ?? 'ИНН')
                            ->maxLength(20),
                        Forms\Components\TextInput::make('registration_number')
                            ->label(__('widgets.organizations.ogrn') ?? 'ОГРН')
                            ->maxLength(20),
                    ])->columns(2),

                Section::make('Контакты')
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('address')
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ])->columns(2),

                Section::make('Статус')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Активна')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('widgets.organizations.name') ?? 'Название')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tax_number')
                    ->label(__('widgets.organizations.inn') ?? 'ИНН')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizations::route('/'),
            'create' => Pages\CreateOrganization::route('/create'),
            'edit' => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = self::getSystemAdmin();

        return $user !== null && app(OrganizationResourcePolicy::class)->viewAny($user);
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
}
