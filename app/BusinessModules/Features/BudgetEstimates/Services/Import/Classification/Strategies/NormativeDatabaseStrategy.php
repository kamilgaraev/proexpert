<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\Strategies;

use App\BusinessModules\Features\BudgetEstimates\Contracts\ClassificationStrategyInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\ClassificationResult;
use Illuminate\Support\Facades\DB;

class NormativeDatabaseStrategy implements ClassificationStrategyInterface
{
    private array $cache = [];

    public function getName(): string
    {
        return 'normative_database';
    }

    public function classify(string $code, string $name, ?string $unit = null, ?float $price = null): ?ClassificationResult
    {
        if (empty($code)) {
            return null;
        }

        // Чистим код для поиска (иногда в сметах бывают пробелы или лишние символы)
        $code = trim($code);
        
        // Попытка точного поиска
        $result = $this->findInDatabase($code);

        if ($result) {
            return new ClassificationResult($result->type, 1.0, 'normative_db_exact');
        }

        // Попытка поиска по очищенному коду (если есть версия без дефисов и т.д.)
        // Но коды КСР важны в точности.
        
        return null;
    }

    public function classifyBatch(array $items): array
    {
        $codes = [];
        $map = [];

        foreach ($items as $index => $item) {
            if (!empty($item['code'])) {
                $code = trim($item['code']);
                $codes[] = $code;
                $map[$code][] = $index;
            }
        }

        if (empty($codes)) {
            return [];
        }

        $codes = array_unique($codes);
        $results = [];

        // Batch query
        $rows = DB::table('normative_resources')
            ->whereIn('code', $codes)
            ->get(['code', 'type']);

        foreach ($rows as $row) {
            if (isset($map[$row->code])) {
                foreach ($map[$row->code] as $index) {
                    $results[$index] = new ClassificationResult($row->type, 1.0, 'normative_db_exact');
                }
            }
        }

        return $results;
    }

    private function findInDatabase(string $code): ?object
    {
        if (isset($this->cache[$code])) {
            return $this->cache[$code];
        }

        $row = DB::table('normative_resources')->where('code', $code)->first(['type']);
        
        $this->cache[$code] = $row;

        return $row;
    }
}
