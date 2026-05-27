<?php

declare(strict_types=1);

namespace App\Filament\Support\Concerns;

use App\Services\Filament\SystemAdminAuditService;
use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;

trait HasDestructiveActionGuardrails
{
    protected static function guardedDeleteAction(string $translationKey): DeleteAction
    {
        return DeleteAction::make()
            ->requiresConfirmation()
            ->modalHeading(trans_message("filament_actions.delete.{$translationKey}.heading"))
            ->modalDescription(trans_message("filament_actions.delete.{$translationKey}.description"))
            ->modalSubmitActionLabel(trans_message('filament_actions.delete.confirm'))
            ->after(function (Model $record): void {
                app(SystemAdminAuditService::class)->recordDeletedModel($record, static::class);
            });
    }
}
