<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\PostgresConnection;
use PDO;
use PDOStatement;
use Pdo\Pgsql;
use Throwable;
use WeakReference;

final class NativeParameterPostgresConnection extends PostgresConnection
{
    private ?WeakReference $nativeSelectStatement = null;

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        $previous = $this->nativeSelectStatement;
        $this->nativeSelectStatement = null;
        try {
            return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
                if ($this->pretending()) {
                    return [];
                }

                $pdo = $this->getPdoForSelect($useReadPdo);
                $native = $this->supportsNativeParameters($pdo);
                $statement = $native ? $pdo->prepare($query, [Pgsql::ATTR_DISABLE_PREPARES => true]) : $pdo->prepare($query);
                $this->nativeSelectStatement = $native ? WeakReference::create($statement) : null;
                $statement = $this->prepared($statement);
                $this->bindValues($statement, $this->prepareBindings($bindings));
                $statement->execute();

                return $statement->fetchAll();
            });
        } finally {
            $this->nativeSelectStatement = $previous;
        }
    }

    public function usesNativeParameterStatement(PDOStatement $statement): bool
    {
        return $this->nativeSelectStatement?->get() === $statement;
    }

    private function supportsNativeParameters(mixed $pdo): bool
    {
        if (! $pdo instanceof PDO || ! in_array($pdo::class, [PDO::class, Pgsql::class], true)) {
            return false;
        }
        try {
            return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' && $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false;
        } catch (Throwable) {
            return false;
        }
    }
}
