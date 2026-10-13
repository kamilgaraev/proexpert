<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\LegacyEstimatorReconciliationCandidates;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\LegacyEstimatorReconciliationData;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\ReconcileLegacyEstimatorPaidBalance;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use App\Models\Organization;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LegacyEstimatorReconciliationAction
{
    public static function make(Organization $organization): Action
    {
        $refresh = static function (Get $get, Set $set) use ($organization): void {
            foreach (['expected_allocation_hash', 'expected_purchase_hash', 'expected_sale_hash', 'paid_ceiling', 'sale_unit_price',
                'remaining_cash_rub', 'evidence_reference', 'evidence_sha256', 'reason'] as $field) {
                $set($field, null);
            }
            $set('financial_attestation_confirmed', false);
            $actor = SystemAdminAccess::user();
            if ($actor === null || ! $get('allocation_id') || ! $get('sale_pack_id')) {
                return;
            }
            try {
                $preview = app(ReconcileLegacyEstimatorPaidBalance::class)->preview((int) $actor->id, (int) $organization->id,
                    (int) $get('allocation_id'), (string) $get('sale_pack_id'));
                $sale = $preview['sale_price'];
                $set('expected_allocation_hash', $preview['allocation_hash']);
                $set('expected_purchase_hash', $preview['purchase_hash']);
                $set('expected_sale_hash', $preview['sale_price_hash']);
                $set('paid_ceiling', BigDecimal::of($preview['paid_amount_minor'])->dividedBy(100, 2)->__toString());
                $set('sale_unit_price', BigDecimal::of($sale['pack_amount_minor'])->multipliedBy(100)
                    ->dividedBy($sale['pack_units_minor'])->dividedBy(100, 2)->__toString());
            } catch (\DomainException|ModelNotFoundException) {
                Notification::make()->warning()->title(trans_message('estimate_evaluation.legacy_preview_failed'))->send();
            }
        };

        return Action::make('reconcile_legacy_estimator_balance')
            ->label(trans_message('estimate_evaluation.legacy_reconcile_action'))
            ->modalDescription(trans_message('estimate_evaluation.legacy_reconcile_description'))
            ->modalSubmitActionLabel(trans_message('estimate_evaluation.legacy_reconcile_submit'))
            ->visible(static fn (): bool => config('estimate-generation.credits.legacy_reconciliation_enabled', false)
                && SystemAdminAccess::can(FilamentPermission::PAYMENTS_MANAGE))
            ->schema([
                Select::make('allocation_id')->label(trans_message('estimate_evaluation.legacy_allocation'))
                    ->options(static fn (): array => app(LegacyEstimatorReconciliationCandidates::class)->forOrganization(
                        (int) SystemAdminAccess::user()?->id, (int) $organization->id))->required()->live()->afterStateUpdated($refresh),
                Select::make('sale_pack_id')->label(trans_message('estimate_evaluation.legacy_sale_pack'))->options([
                    'ai-credits-1000' => trans_message('estimate_evaluation.legacy_sale_pack_base'),
                    'ai-credits-5000' => trans_message('estimate_evaluation.legacy_sale_pack_medium'),
                    'ai-credits-10000' => trans_message('estimate_evaluation.legacy_sale_pack_large'),
                ])->default('ai-credits-1000')->required()->live()->afterStateUpdated($refresh),
                TextInput::make('paid_ceiling')->label(trans_message('estimate_evaluation.legacy_paid_ceiling'))->readOnly()->dehydrated(false),
                TextInput::make('sale_unit_price')->label(trans_message('estimate_evaluation.legacy_sale_unit_price'))->readOnly()->dehydrated(false),
                TextInput::make('remaining_cash_rub')->label(trans_message('estimate_evaluation.legacy_remaining_money'))
                    ->required()->rules(['regex:/\A\d{1,11}(?:[.,]\d{1,2})?\z/']),
                TextInput::make('evidence_reference')->label(trans_message('estimate_evaluation.legacy_evidence_reference'))->required()->maxLength(256),
                TextInput::make('evidence_sha256')->label(trans_message('estimate_evaluation.legacy_evidence_sha256'))->required()->rules(['regex:/\A[a-f0-9]{64}\z/']),
                TextInput::make('reason')->label(trans_message('estimate_evaluation.legacy_reconcile_reason'))->required()->maxLength(800),
                Checkbox::make('financial_attestation_confirmed')->label(trans_message('estimate_evaluation.legacy_attestation'))->accepted(),
                Hidden::make('request_id')->default(static fn (): string => (string) Str::uuid())->required(),
                Hidden::make('expected_allocation_hash')->required(),
                Hidden::make('expected_purchase_hash')->required(),
                Hidden::make('expected_sale_hash')->required(),
            ])
            ->action(static function (array $data) use ($organization): void {
                $actor = SystemAdminAccess::user();
                if ($actor === null || ! ($data['financial_attestation_confirmed'] ?? false)) {
                    throw ValidationException::withMessages(['financial_attestation_confirmed' => trans_message('estimate_evaluation.legacy_attestation')]);
                }
                if (! config('estimate-generation.credits.legacy_reconciliation_enabled', false)) {
                    throw ValidationException::withMessages(['allocation_id' => trans_message('estimate_evaluation.legacy_preview_failed')]);
                }
                try {
                    $remaining = BigDecimal::of(str_replace(',', '.', (string) $data['remaining_cash_rub']))->multipliedBy(100)->toInt();
                    app(ReconcileLegacyEstimatorPaidBalance::class)->confirm((int) $actor->id, new LegacyEstimatorReconciliationData(
                        (int) $organization->id, (int) $data['allocation_id'], (string) $data['request_id'],
                        (string) $data['expected_allocation_hash'], (string) $data['expected_purchase_hash'], (string) $data['expected_sale_hash'],
                        $remaining, (string) $data['sale_pack_id'], (string) $data['evidence_reference'], (string) $data['evidence_sha256'], (string) $data['reason']));
                } catch (\DomainException|\InvalidArgumentException|ModelNotFoundException) {
                    throw ValidationException::withMessages(['allocation_id' => trans_message('estimate_evaluation.legacy_reconcile_conflict')]);
                }
                Notification::make()->success()->title(trans_message('estimate_evaluation.legacy_reconcile_success'))->send();
            });
    }
}
