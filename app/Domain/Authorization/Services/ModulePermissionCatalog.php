<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

use App\Models\Module;
use Illuminate\Database\DeadlockException;
use Throwable;

class ModulePermissionCatalog
{
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

        $query = Module::query();
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
            if ($e !== $failure || $e instanceof DeadlockException || $connection->transactionLevel() !== $originalLevel) {
                throw $e;
            }

            return null;
        }
    }
}
