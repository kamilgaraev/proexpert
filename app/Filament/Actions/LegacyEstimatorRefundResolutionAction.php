<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\LegacyEstimatorRefundResolutionData;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\ResolveLegacyEstimatorRefundHold;
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

final class LegacyEstimatorRefundResolutionAction
{
    public static function make(Organization $organization): Action
    {
        return Action::make('resolve_legacy_estimator_refund')
            ->label(trans_message('estimate_evaluation.legacy_refund_resolve_action'))
            ->modalDescription(trans_message('estimate_evaluation.legacy_refund_resolve_description'))
            ->modalSubmitActionLabel(trans_message('estimate_evaluation.legacy_refund_resolve_submit'))
            ->visible(static fn (): bool => config('estimate-generation.credits.legacy_reconciliation_enabled', false)
                && SystemAdminAccess::can(FilamentPermission::PAYMENTS_MANAGE))
            ->schema([
                Select::make('reconciliation_id')->label(trans_message('estimate_evaluation.legacy_restore_record'))
                    ->options(static function () use ($organization): array {
                        $result = [];
                        foreach (app(ResolveLegacyEstimatorRefundHold::class)->candidates((int) SystemAdminAccess::user()?->id,
                            (int) $organization->id) as $id => $units) {
                            $result[$id] = trans_message('estimate_evaluation.legacy_refund_candidate', ['id' => $id,
                                'units' => BigDecimal::of($units)->dividedBy(100, 2)->__toString()]);
                        }

                        return $result;
                    })->required()->live()->afterStateUpdated(static function (Get $get, Set $set) use ($organization): void {
                        foreach (['expected_snapshot_hash', 'maximum_cash_rub', 'remaining_cash_rub', 'evidence_reference', 'evidence_sha256', 'reason'] as $field) {
                            $set($field, null);
                        }
                        $set('financial_attestation_confirmed', false);
                        $actor = SystemAdminAccess::user();
                        if ($actor === null || ! $get('reconciliation_id')) {
                            return;
                        }
                        try {
                            $preview = app(ResolveLegacyEstimatorRefundHold::class)->preview((int) $actor->id, (int) $organization->id,
                                (int) $get('reconciliation_id'));
                            $set('expected_snapshot_hash', $preview['snapshot_hash']);
                            $set('maximum_cash_rub', BigDecimal::of($preview['maximum_paid_amount_minor'])->dividedBy(100, 2)->__toString());
                        } catch (\DomainException|ModelNotFoundException) {
                            Notification::make()->warning()->title(trans_message('estimate_evaluation.legacy_refund_resolve_failed'))->send();
                        }
                    }),
                TextInput::make('maximum_cash_rub')->label(trans_message('estimate_evaluation.legacy_refund_ceiling'))->readOnly()->dehydrated(false),
                TextInput::make('remaining_cash_rub')->label(trans_message('estimate_evaluation.legacy_remaining_money'))
                    ->required()->rules(['regex:/\A\d{1,11}(?:[.,]\d{1,2})?\z/']),
                TextInput::make('evidence_reference')->label(trans_message('estimate_evaluation.legacy_evidence_reference'))->required()->maxLength(256),
                TextInput::make('evidence_sha256')->label(trans_message('estimate_evaluation.legacy_evidence_sha256'))->required()->rules(['regex:/\A[a-f0-9]{64}\z/']),
                TextInput::make('reason')->label(trans_message('estimate_evaluation.legacy_reconcile_reason'))->required()->maxLength(800),
                Checkbox::make('financial_attestation_confirmed')->label(trans_message('estimate_evaluation.legacy_refund_attestation'))->accepted(),
                Hidden::make('request_id')->default(static fn (): string => (string) Str::uuid())->required(),
                Hidden::make('expected_snapshot_hash')->required(),
            ])
            ->action(static function (array $data) use ($organization): void {
                $actor = SystemAdminAccess::user();
                if ($actor === null || ! ($data['financial_attestation_confirmed'] ?? false)) {
                    throw ValidationException::withMessages(['financial_attestation_confirmed' => trans_message('estimate_evaluation.legacy_refund_attestation')]);
                }
                try {
                    $remaining = BigDecimal::of(str_replace(',', '.', (string) $data['remaining_cash_rub']))->multipliedBy(100)->toInt();
                    app(ResolveLegacyEstimatorRefundHold::class)->confirm((int) $actor->id, new LegacyEstimatorRefundResolutionData(
                        (int) $organization->id, (int) $data['reconciliation_id'], (string) $data['request_id'],
                        (string) $data['expected_snapshot_hash'], $remaining, (string) $data['evidence_reference'],
                        (string) $data['evidence_sha256'], (string) $data['reason']));
                } catch (\DomainException|\InvalidArgumentException|ModelNotFoundException) {
                    throw ValidationException::withMessages(['reconciliation_id' => trans_message('estimate_evaluation.legacy_refund_resolve_failed')]);
                }
                Notification::make()->success()->title(trans_message('estimate_evaluation.legacy_refund_resolve_success'))->send();
            });
    }
}
