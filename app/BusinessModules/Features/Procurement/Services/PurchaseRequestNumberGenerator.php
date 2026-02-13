<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Генератор уникальных номеров для заявок на закупку.
 * Гарантирует последовательную нумерацию (1, 2, 3...) в рамках месяца и организации.
 * Использует атомарный upsert для защиты от гонок (race conditions).
 */
class PurchaseRequestNumberGenerator
{
    private const PREFIX = 'ЗЗ';

    /**
     * Генерирует следующий номер заявки в формате: ЗЗ-YYYYMM-XXXX
     * Например: ЗЗ-202602-0001
     *
     * @param int $organizationId ID организации
     * @return string Сгенерированный номер
     */
    public function generate(int $organizationId): string
    {
        $year = (int) date('Y');
        $month = (int) date('m');
        
        $prefix = sprintf('%s-%d%02d-', self::PREFIX, $year, $month);

        // Используем атомарный запрос для получения следующего номера.
        // 1. Пытаемся вставить новую запись счетчика для текущего месяца.
        //    Если записи нет, инициализируем её значением (MAX существующих номеров + 1) или 1.
        // 2. Если запись уже есть (конфликт), обновляем её, увеличивая счетчик.
        //    При этом используем GREATEST, чтобы убедиться, что счетчик не отстает от реальных данных в таблице заявок.
        
        // $prefixPattern для поиска в БД: 'ЗЗ-202602-%'
        $prefixPattern = $prefix . '%';

        $sql = "
            INSERT INTO purchase_request_number_counters (organization_id, year, month, last_number, created_at, updated_at)
            
            -- Вычисляем начальное значение
            SELECT ?, ?, ?,
                COALESCE(
                    (SELECT MAX(CAST(SUBSTRING(request_number FROM '([0-9]+)$') AS INTEGER))
                     FROM purchase_requests
                     WHERE organization_id = ?
                       AND request_number LIKE ?),
                    0
                ) + 1,
                NOW(), NOW()
                
            ON CONFLICT (organization_id, year, month)
            DO UPDATE SET
                last_number = GREATEST(
                    purchase_request_number_counters.last_number + 1,
                    COALESCE(
                        (SELECT MAX(CAST(SUBSTRING(request_number FROM '([0-9]+)$') AS INTEGER))
                         FROM purchase_requests
                         WHERE organization_id = EXCLUDED.organization_id
                           AND request_number LIKE '{$prefix}%'), -- Используем то же условие
                        0
                    ) + 1
                ),
                updated_at = NOW()
            RETURNING last_number
        ";

        try {
            $result = DB::selectOne($sql, [
                $organizationId,
                $year,
                $month,
                $organizationId,
                $prefixPattern
            ]);

            $newNumber = $result->last_number;
            $requestNumber = sprintf('%s%04d', $prefix, $newNumber);

            Log::debug('procurement.purchase_request.number_generated', [
                'organization_id' => $organizationId,
                'year' => $year,
                'month' => $month,
                'generated_number' => $requestNumber,
                'counter_value' => $newNumber,
            ]);

            return $requestNumber;
        } catch (\Exception $e) {
            Log::error('procurement.purchase_request.number_generation_failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
