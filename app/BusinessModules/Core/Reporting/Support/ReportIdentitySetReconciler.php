<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Support;

final class ReportIdentitySetReconciler
{
    public static function gapCount(iterable $ownerKeys, iterable $projectedKeys): int
    {
        $owners = collect($ownerKeys);
        $projected = collect($projectedKeys);

        return $owners->diff($projected)->count()
            + $projected->diff($owners)->count()
            + ($owners->count() - $owners->unique()->count())
            + ($projected->count() - $projected->unique()->count());
    }
}
