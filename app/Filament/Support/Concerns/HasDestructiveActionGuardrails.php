<?php

declare(strict_types=1);

namespace App\Filament\Support\Concerns;

use Filament\Actions\DeleteAction;

trait HasDestructiveActionGuardrails
{
    protected static function guardedDeleteAction(string $translationKey): DeleteAction
    {
        return DeleteAction::make()
            ->requiresConfirmation()
            ->modalHeading(trans_message("filament_actions.delete.{$translationKey}.heading"))
            ->modalDescription(trans_message("filament_actions.delete.{$translationKey}.description"))
            ->modalSubmitActionLabel(trans_message('filament_actions.delete.confirm'));
    }
}

