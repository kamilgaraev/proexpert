<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

use App\Models\Module;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;
use PDOException;
use Throwable;

class ModulePermissionCatalog
{
    use DetectsConcurrencyErrors;
    use DetectsLostConnections;

    public function __construct(private readonly Module $model) {}

    public static function canonicalSlug(string $slug): string
    {
        return $slug === 'estimate-generation' ? 'ai-estimates' : $slug;
    }

    public function load(array $modules): ?array
    {
        $slugs = [];
        foreach ($modules as $slug => $permissions) {
            if (in_array('*', $permissions, true)) {
                $slugs[self::canonicalSlug($slug)] = true;
            }
        }
        if ($slugs === []) {
            return [];
        }

        $query = $this->model->newQuery();
        $connection = $query->getConnection();
        $originalLevel = $connection->transactionLevel();
        $failure = null;
        $read = static function () use ($query, $slugs, &$failure): array {
            try {
                $catalog = [];
                foreach (array_chunk(array_keys($slugs), 500) as $chunk) {
                    foreach ((clone $query)->whereIn('slug', $chunk)->get(['slug', 'permissions']) as $module) {
                        $catalog[$module->slug] = $module;
                    }
                }

                return $catalog;
            } catch (Throwable $e) {
                $failure = $e;
                throw $e;
            }
        };

        try {
            return $originalLevel > 0 ? $connection->transaction($read, 1) : $read();
        } catch (Throwable $e) {
            if ($e !== $failure || $this->isTransactionFailure($e) || $connection->transactionLevel() !== $originalLevel) {
                throw $e;
            }

            return null;
        }
    }

    private function isTransactionFailure(Throwable $error): bool
    {
        do {
            if ($error instanceof DeadlockException || $this->causedByConcurrencyError($error) || $this->causedByLostConnection($error)) {
                return true;
            }
            if ($error instanceof PDOException) {
                $state = (string) ($error->errorInfo[0] ?? $error->getCode());
                if (in_array(substr($state, 0, 2), ['08', '25', '2D', '40'], true)) {
                    return true;
                }
            }
            $error = $error->getPrevious();
        } while ($error !== null);

        return false;
    }
}
