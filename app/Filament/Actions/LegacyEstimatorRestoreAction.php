<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\LegacyEstimatorReconciliationCandidates;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\RestoreLegacyEstimatorPaidBalance;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LegacyEstimatorRestoreAction
{
    public static function make(Organization $organization): Action
    {
        return Action::make('restore_legacy_estimator_balance')
            ->label(trans_message('estimate_evaluation.legacy_restore_action'))
            ->modalDescription(trans_message('estimate_evaluation.legacy_restore_description'))
            ->modalSubmitActionLabel(trans_message('estimate_evaluation.legacy_restore_submit'))
            ->visible(static fn (): bool => SystemAdminAccess::can(FilamentPermission::PAYMENTS_MANAGE)
                && \Illuminate\Support\Facades\Schema::hasTable(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\ReconcileLegacyEstimatorPaidBalance::TABLE)
                && app(LegacyEstimatorReconciliationCandidates::class)->forRestore((int) SystemAdminAccess::user()?->id, (int) $organization->id) !== [])
            ->requiresConfirmation()
            ->schema([
                Select::make('reconciliation_id')->label(trans_message('estimate_evaluation.legacy_restore_record'))
                    ->options(static fn (): array => app(LegacyEstimatorReconciliationCandidates::class)->forRestore(
                        (int) SystemAdminAccess::user()?->id, (int) $organization->id))->required(),
                TextInput::make('reason')->label(trans_message('estimate_evaluation.legacy_restore_reason'))->required()->maxLength(800),
                Hidden::make('request_id')->default(static fn (): string => (string) Str::uuid())->required(),
            ])
            ->action(static function (array $data) use ($organization): void {
                $actor = SystemAdminAccess::user();
                if ($actor === null) {
                    throw ValidationException::withMessages(['reconciliation_id' => trans_message('estimate_evaluation.legacy_restore_failed')]);
                }
                try {
                    app(RestoreLegacyEstimatorPaidBalance::class)->handle((int) $actor->id, (int) $organization->id,
                        (int) $data['reconciliation_id'], (string) $data['request_id'], (string) $data['reason']);
                } catch (\DomainException) {
                    throw ValidationException::withMessages(['reconciliation_id' => trans_message('estimate_evaluation.legacy_restore_failed')]);
                }
                Notification::make()->success()->title(trans_message('estimate_evaluation.legacy_restore_success'))->send();
            });
    }
}
