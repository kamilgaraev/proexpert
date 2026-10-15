<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CommercialBankRefundResource\Pages\ListCommercialBankRefunds;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Models\CommercialBankRefund;
use App\Services\Billing\Bank\BankMoney;
use App\Services\Billing\Bank\BankRefundService;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CommercialBankRefundResource extends Resource
{
    protected static ?string $model = CommercialBankRefund::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::billing();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('bank_billing.operator_refunds');
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
            TextColumn::make('invoice.number')->label(trans_message('bank_billing.document_invoice'))->searchable(),
            TextColumn::make('amount_minor')->label(trans_message('bank_billing.amount'))->formatStateUsing(fn (int $state): string => BankMoney::decimal($state)),
            TextColumn::make('status')->label(trans_message('bank_billing.status'))->formatStateUsing(fn (string $state): string => trans_message('bank_billing.refund_status_'.$state))->badge(),
            TextColumn::make('operation.payer_account')->label(trans_message('bank_billing.refund_account'))->copyable(),
            TextColumn::make('reference')->label(trans_message('bank_billing.refund_reference'))->copyable(),
            TextColumn::make('reason')->label(trans_message('bank_billing.operator_reason'))->wrap(),
            TextColumn::make('completed_at')->label(trans_message('bank_billing.document_date'))->dateTime(),
        ])->filters([SelectFilter::make('status')->label(trans_message('bank_billing.status'))->options(collect(['requested', 'awaiting_bank', 'completed', 'rejected'])
            ->mapWithKeys(fn ($status): array => [$status => trans_message('bank_billing.refund_status_'.$status)])->all())])
            ->actions([self::decision(true), self::decision(false)])->bulkActions([])->defaultSort('id', 'desc');
    }

    private static function decision(bool $approve): Action
    {
        return Action::make($approve ? 'approve' : 'reject')->label(trans_message($approve ? 'bank_billing.operator_approve' : 'bank_billing.operator_reject'))
            ->requiresConfirmation()->modalDescription(trans_message('bank_billing.operator_notice'))
            ->visible(fn (CommercialBankRefund $record): bool => $record->status === 'requested' && SystemAdminAccess::can(FilamentPermission::PAYMENTS_MANAGE))
            ->action(function (CommercialBankRefund $record) use ($approve): void {
                $actor = SystemAdminAccess::user();
                abort_unless($actor !== null, 403);
                try {
                    app(BankRefundService::class)->decide($actor, (int) $record->id, $approve);
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages(['status' => trans_message('bank_billing.refund_conflict')]);
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListCommercialBankRefunds::route('/')];
    }
}
