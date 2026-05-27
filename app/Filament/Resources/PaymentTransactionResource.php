<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Support\TableEmptyState;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Filament\Resources\PaymentTransactionResource\Pages;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
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

class PaymentTransactionResource extends Resource
{
    protected static ?string $model = PaymentTransaction::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return NavigationGroups::billing();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('widgets.payments.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('widgets.payments.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('widgets.payments.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('widgets.payments.sections.transaction'))
                    ->schema([
                        Infolists\Components\TextEntry::make('amount')
                            ->label(trans_message('widgets.payments.amount'))
                            ->money('RUB'),
                        Infolists\Components\TextEntry::make('currency')
                            ->label(trans_message('widgets.payments.currency')),
                        Infolists\Components\TextEntry::make('status')
                            ->label(trans_message('widgets.payments.status'))
                            ->formatStateUsing(fn (mixed $state): string => self::statusLabel($state))
                            ->badge()
                            ->color(fn (mixed $state): string => self::statusColor($state)),
                        Infolists\Components\TextEntry::make('payment_method')
                            ->label(trans_message('widgets.payments.method'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('transaction_date')
                            ->label(trans_message('widgets.payments.transaction_date'))
                            ->date()
                            ->placeholder(trans_message('widgets.common.empty_value')),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.payments.sections.context'))
                    ->schema([
                        Infolists\Components\TextEntry::make('organization.name')
                            ->label(trans_message('widgets.payments.organization'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('payerOrganization.name')
                            ->label(trans_message('widgets.payments.payer'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('payeeOrganization.name')
                            ->label(trans_message('widgets.payments.payee'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('project.name')
                            ->label(trans_message('widgets.payments.project'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                    ])
                    ->columns(2),
                Section::make(trans_message('widgets.payments.sections.provider'))
                    ->schema([
                        Infolists\Components\TextEntry::make('payment_gateway_id')
                            ->label(trans_message('widgets.payments.provider_id'))
                            ->formatStateUsing(fn (?string $state): string => self::maskExternalId($state))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('reference_number')
                            ->label(trans_message('widgets.payments.reference_number'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('bank_transaction_id')
                            ->label(trans_message('widgets.payments.bank_transaction_id'))
                            ->formatStateUsing(fn (?string $state): string => self::maskExternalId($state))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                        Infolists\Components\TextEntry::make('metadata.failure_reason')
                            ->label(trans_message('widgets.payments.failure_reason'))
                            ->placeholder(trans_message('widgets.common.empty_value')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'payments', 'heroicon-o-credit-card')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'organization',
                'payerOrganization',
                'payeeOrganization',
                'project',
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('organization.name')
                    ->label(trans_message('widgets.payments.organization'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label(trans_message('widgets.payments.amount'))
                    ->money('RUB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency')
                    ->label(trans_message('widgets.payments.currency'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->label(trans_message('widgets.payments.status'))
                    ->formatStateUsing(fn (mixed $state): string => self::statusLabel($state))
                    ->badge()
                    ->color(fn (mixed $state): string => self::statusColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_method')
                    ->label(trans_message('widgets.payments.method'))
                    ->placeholder(trans_message('widgets.common.empty_value')),
                Tables\Columns\TextColumn::make('payment_gateway_id')
                    ->label(trans_message('widgets.payments.provider_id'))
                    ->formatStateUsing(fn (?string $state): string => self::maskExternalId($state))
                    ->placeholder(trans_message('widgets.common.empty_value'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('transaction_date')
                    ->label(trans_message('widgets.payments.transaction_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(trans_message('widgets.payments.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(trans_message('widgets.payments.status'))
                    ->options(self::statusOptions()),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentTransactions::route('/'),
            'view' => Pages\ViewPaymentTransaction::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::PAYMENTS_VIEW);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof PaymentTransaction && self::canViewAny();
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

    private static function statusLabel(mixed $state): string
    {
        if ($state instanceof PaymentTransactionStatus) {
            return self::statusOptions()[$state->value] ?? $state->value;
        }

        if (! is_string($state) || $state === '') {
            return trans_message('widgets.common.empty_value');
        }

        return self::statusOptions()[$state] ?? $state;
    }

    private static function statusColor(mixed $state): string
    {
        $value = $state instanceof PaymentTransactionStatus ? $state->value : (string) $state;

        return match ($value) {
            'completed' => 'success',
            'pending', 'processing' => 'warning',
            'failed', 'cancelled' => 'danger',
            'refunded' => 'info',
            default => 'gray',
        };
    }

    private static function maskExternalId(?string $value): string
    {
        if ($value === null || $value === '') {
            return trans_message('widgets.common.empty_value');
        }

        return strlen($value) <= 8 ? $value : substr($value, 0, 4) . '...' . substr($value, -4);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return [
            'pending' => trans_message('widgets.payments.status_pending'),
            'processing' => trans_message('widgets.payments.status_processing'),
            'completed' => trans_message('widgets.payments.status_completed'),
            'failed' => trans_message('widgets.payments.status_failed'),
            'cancelled' => trans_message('widgets.payments.status_cancelled'),
            'refunded' => trans_message('widgets.payments.status_refunded'),
        ];
    }
}
