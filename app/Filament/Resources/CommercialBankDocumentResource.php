<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CommercialBankDocumentResource\Pages\ListCommercialBankDocuments;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Models\CommercialBankDocument;
use App\Services\Billing\Bank\BankDocumentRecoveryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CommercialBankDocumentResource extends Resource
{
    protected static ?string $model = CommercialBankDocument::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::billing();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('bank_billing.operator_documents');
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
            TextColumn::make('organization_id')->label(trans_message('bank_billing.organization')),
            TextColumn::make('kind')->label(trans_message('bank_billing.document_kind'))
                ->formatStateUsing(fn (string $state): string => trans_message('bank_billing.document_'.$state)),
            TextColumn::make('provider')->label(trans_message('bank_billing.document_provider')),
            TextColumn::make('status')->label(trans_message('bank_billing.status'))
                ->formatStateUsing(fn (string $state): string => trans_message('bank_billing.document_status_'.$state)),
            TextColumn::make('provider_document_id')->label(trans_message('bank_billing.provider_document_id'))->copyable(),
            TextColumn::make('failure_code')->label(trans_message('bank_billing.review')),
            TextColumn::make('review_note')->label(trans_message('bank_billing.operator_reason'))->wrap(),
        ])->filters([SelectFilter::make('status')->label(trans_message('bank_billing.status'))->options(
            collect(['pending', 'creating', 'creation_unknown', 'blocked', 'ready'])
                ->mapWithKeys(fn (string $status): array => [$status => trans_message('bank_billing.document_status_'.$status)])->all()
        )])->actions([
            Action::make('resolve')->label(trans_message('bank_billing.document_resolve'))
                ->visible(fn (CommercialBankDocument $record): bool => $record->provider === 'tochka'
                    && $record->provider_document_id === null && in_array($record->status, ['creation_unknown', 'blocked'], true)
                    && SystemAdminAccess::can(FilamentPermission::PAYMENTS_MANAGE))
                ->schema([
                    TextInput::make('provider_id')->label(trans_message('bank_billing.provider_document_id'))->uuid()->nullable(),
                    Textarea::make('reason')->label(trans_message('bank_billing.operator_reason'))->required()->minLength(10)->maxLength(1000),
                    Toggle::make('confirmed')->label(trans_message('bank_billing.document_resolution_confirmed'))->accepted()->required(),
                ])->action(function (CommercialBankDocument $record, array $data): void {
                    $actor = SystemAdminAccess::user();
                    abort_unless($actor !== null, 403);
                    try {
                        app(BankDocumentRecoveryService::class)->resolve($actor, (int) $record->id,
                            filled($data['provider_id'] ?? null) ? (string) $data['provider_id'] : null,
                            (string) $data['reason'], ($data['confirmed'] ?? false) === true);
                    } catch (\InvalidArgumentException|\RuntimeException) {
                        throw ValidationException::withMessages(['reason' => trans_message('bank_billing.document_resolution_failed')]);
                    }
                }),
        ])->bulkActions([])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCommercialBankDocuments::route('/')];
    }
}
