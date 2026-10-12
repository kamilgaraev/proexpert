<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use Illuminate\Support\Facades\DB;
use LogicException;

final class DocumentObjectPublicationLock
{
    public function acquire(string $path): void
    {
        if (DB::transactionLevel() < 1 || DB::getDriverName() !== 'pgsql') {
            throw new LogicException('document_object_publication_requires_postgres_transaction');
        }
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['estimate-document-object:'.$path]);
    }
}
