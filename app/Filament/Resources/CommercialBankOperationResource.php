<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CommercialBankOperationResource\Pages\ListCommercialBankOperations;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Models\CommercialBankInvoice;
use App\Models\CommercialBankOperation;
use App\Services\Billing\Bank\BankMoney;
use App\Services\Billing\Bank\BankSettlementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CommercialBankOperationResource extends Resource
{
    protected static ?string $model = CommercialBankOperation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::billing();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('bank_billing.operator_operations');
    }

    public static function getPluralModelLabel(): string
    {
        return self::getNavigationLabel();
    }

    public static function canViewAny(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::PAYMENTS_VIEW);
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

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('booked_on')->label(trans_message('bank_billing.document_date'))->date()->sortable(),
            TextColumn::make('direction')->label(trans_message('bank_billing.direction'))->formatStateUsing(fn (string $state): string => trans_message('bank_billing.direction_'.$state)),
            TextColumn::make('amount_minor')->label(trans_message('bank_billing.amount'))->formatStateUsing(fn (int $state): string => BankMoney::decimal($state)),
            TextColumn::make('allocated_minor')->label(trans_message('bank_billing.allocated'))->formatStateUsing(fn (int $state): string => BankMoney::decimal($state)),
            TextColumn::make('payer_inn')->label(trans_message('bank_billing.inn'))->searchable(),
            TextColumn::make('purpose')->label(trans_message('bank_billing.purpose'))->wrap()->limit(250)->searchable(),
            TextColumn::make('review_reason')->label(trans_message('bank_billing.review'))->formatStateUsing(fn (?string $state): string => $state ? trans_message('bank_billing.review_'.$state) : '—'),
            TextColumn::make('review_note')->label(trans_message('bank_billing.operator_reason'))->wrap()->toggleable(),
        ])->filters([SelectFilter::make('direction')->label(trans_message('bank_billing.direction'))->options([
            'credit' => trans_message('bank_billing.direction_credit'), 'debit' => trans_message('bank_billing.direction_debit'),
        ])])->actions([
            Action::make('match')->label(trans_message('bank_billing.operator_match'))
                ->visible(fn (CommercialBankOperation $record): bool => $record->direction === 'credit'
                    && $record->allocated_minor < $record->amount_minor && $record->review_reason !== 'bank_operation_changed'
                    && preg_match('/\A(?:40[567]\d{17}|40802\d{15})\z/', (string) $record->payer_account) === 1
                    && SystemAdminAccess::can(FilamentPermission::PAYMENTS_MANAGE))
                ->schema([
                    Select::make('invoice_id')->label(trans_message('bank_billing.document_invoice'))->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array => CommercialBankInvoice::query()
                            ->whereIn('status', ['awaiting_payment', 'partially_paid', 'paid', 'expired'])
                            ->where('number', 'like', '%'.strtoupper($search).'%')->orderByDesc('id')->limit(50)->pluck('number', 'id')->all())
                        ->getOptionLabelUsing(fn ($value): ?string => CommercialBankInvoice::query()->whereKey($value)->value('number')),
                    Textarea::make('reason')->label(trans_message('bank_billing.operator_reason'))->required()->minLength(10)->maxLength(1000),
                ])->action(function (CommercialBankOperation $record, array $data): void {
                    $actor = SystemAdminAccess::user();
                    abort_unless($actor !== null, 403);
                    try {
                        app(BankSettlementService::class)->allocateManually($actor, (int) $record->id, (int) $data['invoice_id'], (string) $data['reason']);
                    } catch (\InvalidArgumentException) {
                        throw ValidationException::withMessages(['invoice_id' => trans_message('bank_billing.invalid')]);
                    }
                }),
            Action::make('exclude')->label(trans_message('bank_billing.operator_exclude'))
                ->visible(fn (CommercialBankOperation $record): bool => $record->direction === 'credit'
                    && $record->allocated_minor === 0 && $record->reviewed_by === null && SystemAdminAccess::can(FilamentPermission::PAYMENTS_MANAGE))
                ->schema([Textarea::make('reason')->label(trans_message('bank_billing.operator_reason'))->required()->minLength(10)->maxLength(1000)])
                ->action(function (CommercialBankOperation $record, array $data): void {
                    $actor = SystemAdminAccess::user();
                    abort_unless($actor !== null, 403);
                    try {
                        app(BankSettlementService::class)->exclude($actor, (int) $record->id, (string) $data['reason']);
                    } catch (\InvalidArgumentException) {
                        throw ValidationException::withMessages(['reason' => trans_message('bank_billing.invalid')]);
                    }
                }),
        ])->bulkActions([])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCommercialBankOperations::route('/')];
    }
}
