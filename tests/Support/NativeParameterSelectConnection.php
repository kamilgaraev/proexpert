<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\PostgresConnection;
use Pdo\Pgsql;

final class NativeParameterSelectConnection extends PostgresConnection
{
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            $statement = $this->prepared($this->getPdoForSelect($useReadPdo)->prepare($query, [Pgsql::ATTR_DISABLE_PREPARES => true]));
            $this->bindValues($statement, $this->prepareBindings($bindings));
            $statement->execute();

            return $statement->fetchAll();
        });
    }
}
