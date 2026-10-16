<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CommercialBankPaymentProofResource\Pages\ListCommercialBankPaymentProofs;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Models\CommercialBankPaymentProof;
use App\Services\Billing\Bank\BankPaymentProofService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CommercialBankPaymentProofResource extends Resource
{
    protected static ?string $model = CommercialBankPaymentProof::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::billing();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('bank_billing.proof_title');
    }

    public static function getPluralModelLabel(): string
    {
        return self::getNavigationLabel();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = CommercialBankPaymentProof::query()->where('status', 'review')->count();

        return $count > 0 ? (string) $count : null;
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['invoice.organization']);
    }

    public static function table(Table $table): Table
    {
        $statuses = collect(['pending', 'review', 'confirmed', 'rejected', 'canceled'])
            ->mapWithKeys(fn ($status) => [$status => trans_message('bank_billing.proof_'.$status)])->all();

        return $table->columns([
            TextColumn::make('invoice.number')->label(trans_message('bank_billing.document_invoice'))->searchable(),
            TextColumn::make('invoice.organization.name')->label(trans_message('bank_billing.organization')),
            TextColumn::make('status')->label(trans_message('bank_billing.status'))->formatStateUsing(fn ($state) => $statuses[$state] ?? $state)->badge(),
            TextColumn::make('submitted_at')->label(trans_message('bank_billing.proof_submitted_at'))->dateTime()->sortable(),
            TextColumn::make('temporary_expires_at')->label(trans_message('bank_billing.proof_expires_at'))->dateTime()->sortable(),
            TextColumn::make('review_note')->label(trans_message('bank_billing.operator_reason'))->limit(80),
        ])->filters([SelectFilter::make('status')->label(trans_message('bank_billing.status'))->options($statuses)])
            ->recordActions([
                Action::make('download')->label(trans_message('bank_billing.proof_download'))
                    ->visible(fn () => SystemAdminAccess::can(FilamentPermission::PAYMENTS_MANAGE))
                    ->action(function (CommercialBankPaymentProof $record) {
                        $actor = SystemAdminAccess::user();
                        abort_unless($actor !== null, 403);

                        return app(BankPaymentProofService::class)->downloadForOperator($actor, $record);
                    }),
                Action::make('reject')->label(trans_message('bank_billing.proof_reject'))->requiresConfirmation()
                    ->visible(fn (CommercialBankPaymentProof $record) => SystemAdminAccess::can(FilamentPermission::PAYMENTS_MANAGE)
                        && in_array($record->status, ['pending', 'review'], true))
                    ->schema([Textarea::make('reason')->label(trans_message('bank_billing.operator_reason'))->required()->minLength(10)->maxLength(1000)])
                    ->action(function (CommercialBankPaymentProof $record, array $data): void {
                        $actor = SystemAdminAccess::user();
                        abort_unless($actor !== null, 403);
                        try {
                            app(BankPaymentProofService::class)->reject($actor, $record, (string) $data['reason']);
                        } catch (\InvalidArgumentException) {
                            throw ValidationException::withMessages(['reason' => trans_message('bank_billing.invalid')]);
                        }
                    }),
            ])->bulkActions([])->defaultSort('submitted_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCommercialBankPaymentProofs::route('/')];
    }
}
