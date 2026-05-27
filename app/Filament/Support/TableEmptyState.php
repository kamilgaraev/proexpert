<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Tables\Table;

use function trans_message;

final class TableEmptyState
{
    public static function for(Table $table, string $key, string $icon): Table
    {
        return $table
            ->emptyStateHeading(trans_message("filament_empty_states.{$key}.heading"))
            ->emptyStateDescription(trans_message("filament_empty_states.{$key}.description"))
            ->emptyStateIcon($icon);
    }
}
