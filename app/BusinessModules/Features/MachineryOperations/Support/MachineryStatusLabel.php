<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Support;

use Illuminate\Support\Facades\Lang;

final class MachineryStatusLabel
{
    public static function for(string $group, mixed $status): string
    {
        $normalized = is_string($status) ? trim($status) : '';
        $key = "machinery_operations.{$group}.{$normalized}";

        if ($normalized !== '' && Lang::has($key)) {
            $label = trim((string) trans_message($key));
            if ($label !== '' && $label !== $key) {
                return $label;
            }
        }

        return trans_message('machinery_operations.status_fallback');
    }
}
