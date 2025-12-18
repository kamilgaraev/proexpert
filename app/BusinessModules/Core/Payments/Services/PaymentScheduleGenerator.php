<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Генератор графиков платежей
 */
class PaymentScheduleGenerator
{
    /**
     * Создать график платежей для документа
     */
    public function generate(PaymentDocument $document, array $config): Collection
    {
        DB::beginTransaction();

        try {
            $schedules = collect();

            // Определяем тип графика
            $scheduleType = $config['schedule_type'] ?? 'equal_installments';

            $installments = match($scheduleType) {
                'equal_installments' => $this->generateEqualInstallments($document, $config),
                'percentage_based' => $this->generatePercentageBased($document, $config),
                'milestone_based' => $this->generateMilestoneBased($document, $config),
                'custom' => $this->generateCustom($document, $config),
                'from_contract' => $this->generateFromContract($document, $config),
                default => throw new \InvalidArgumentException("Неизвестный тип графика: {$scheduleType}"),
            };

            // Создаем записи графика
            foreach ($installments as $index => $installment) {
                $schedule = PaymentSchedule::create([
                    'payment_document_id' => $document->id,
                    'installment_number' => $index + 1,
                    'amount' => $installment['amount'],
                    'due_date' => $installment['due_date'],
                    'notes' => $installment['description'] ?? "Платеж №" . ($index + 1),
                    'status' => 'pending',
                ]);

                $schedules->push($schedule);
            }

            Log::info('payment_schedule.generated', [
                'document_id' => $document->id,
                'schedule_type' => $scheduleType,
                'installments_count' => $schedules->count(),
                'total_amount' => $schedules->sum('amount'),
            ]);

            DB::commit();
            return $schedules;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('payment_schedule.generation_failed', [
                'document_id' => $document->id,
                'config' => $config,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Равные платежи
     */
    private function generateEqualInstallments(PaymentDocument $document, array $config): array
    {
        $installmentsCount = $config['installments_count'] ?? 3;
        $startDate = isset($config['start_date']) 
            ? Carbon::parse($config['start_date']) 
            : Carbon::now();
        $intervalDays = $config['interval_days'] ?? 30;

        $installmentAmount = round($document->amount / $installmentsCount, 2);
        $remainder = $document->amount - ($installmentAmount * $installmentsCount);

        $installments = [];

        for ($i = 0; $i < $installmentsCount; $i++) {
            $amount = $installmentAmount;
            
            // Добавляем остаток к последнему платежу
            if ($i === $installmentsCount - 1 && $remainder != 0) {
                $amount += $remainder;
            }

            $dueDate = (clone $startDate)->addDays($intervalDays * $i);

            $installments[] = [
                'amount' => $amount,
                'due_date' => $dueDate,
                'description' => "Равный платеж " . ($i + 1) . " из {$installmentsCount}",
                'metadata' => [
                    'type' => 'equal',
                    'installment_number' => $i + 1,
                    'total_installments' => $installmentsCount,
                ],
            ];
        }

        return $installments;
    }

    /**
     * Процентные платежи (например: аванс 30%, промежуточный 40%, финальный 30%)
     */
    private function generatePercentageBased(PaymentDocument $document, array $config): array
    {
        $percentages = $config['percentages'] ?? [30, 40, 30];
        $startDate = isset($config['start_date']) 
            ? Carbon::parse($config['start_date']) 
            : Carbon::now();
        $intervalDays = $config['interval_days'] ?? 30;

        // Проверка что сумма процентов = 100%
        $totalPercent = array_sum($percentages);
        if (abs($totalPercent - 100) > 0.01) {
            throw new \InvalidArgumentException("Сумма процентов должна быть 100%, получено: {$totalPercent}%");
        }

        $installments = [];
        $calculatedTotal = 0;

        foreach ($percentages as $index => $percent) {
            $amount = round($document->amount * ($percent / 100), 2);
            $calculatedTotal += $amount;

            // Корректируем последний платеж чтобы покрыть остаток
            if ($index === count($percentages) - 1) {
                $amount = $document->amount - ($calculatedTotal - $amount);
            }

            $dueDate = (clone $startDate)->addDays($intervalDays * $index);

            $description = match(true) {
                $index === 0 => "Аванс ({$percent}%)",
                $index === count($percentages) - 1 => "Финальный платеж ({$percent}%)",
                default => "Промежуточный платеж " . ($index + 1) . " ({$percent}%)",
            };

            $installments[] = [
                'amount' => $amount,
                'due_date' => $dueDate,
                'description' => $description,
                'metadata' => [
                    'type' => 'percentage',
                    'percentage' => $percent,
                    'installment_number' => $index + 1,
                ],
            ];
        }

        return $installments;
    }

    /**
     * Платежи по этапам (milestone)
     */
    private function generateMilestoneBased(PaymentDocument $document, array $config): array
    {
        $milestones = $config['milestones'] ?? [];

        if (empty($milestones)) {
            throw new \InvalidArgumentException('Не указаны этапы (milestones)');
        }

        // Проверка что сумма этапов не превышает общую сумму
        $totalAmount = array_sum(array_column($milestones, 'amount'));
        if ($totalAmount > $document->amount) {
            throw new \InvalidArgumentException("Сумма этапов ({$totalAmount}) превышает сумму документа ({$document->amount})");
        }

        $installments = [];

        foreach ($milestones as $index => $milestone) {
            $installments[] = [
                'amount' => $milestone['amount'],
                'due_date' => Carbon::parse($milestone['due_date']),
                'description' => $milestone['description'] ?? "Этап " . ($index + 1),
                'metadata' => [
                    'type' => 'milestone',
                    'milestone_name' => $milestone['name'] ?? null,
                    'milestone_id' => $milestone['id'] ?? null,
                ],
            ];
        }

        return $installments;
    }

    /**
     * Кастомный график
     */
    private function generateCustom(PaymentDocument $document, array $config): array
    {
        $installments = $config['installments'] ?? [];

        if (empty($installments)) {
            throw new \InvalidArgumentException('Не указаны платежи для кастомного графика');
        }

        // Валидация
        foreach ($installments as $installment) {
            if (!isset($installment['amount']) || !isset($installment['due_date'])) {
                throw new \InvalidArgumentException('Каждый платеж должен содержать amount и due_date');
            }
        }

        $totalAmount = array_sum(array_column($installments, 'amount'));
        if (abs($totalAmount - $document->amount) > 0.01) {
            throw new \InvalidArgumentException("Сумма платежей ({$totalAmount}) не равна сумме документа ({$document->amount})");
        }

        // Преобразуем даты
        return array_map(function($installment, $index) {
            return [
                'amount' => $installment['amount'],
                'due_date' => Carbon::parse($installment['due_date']),
                'description' => $installment['description'] ?? "Платеж " . ($index + 1),
                'metadata' => $installment['metadata'] ?? ['type' => 'custom'],
            ];
        }, $installments, array_keys($installments));
    }

    /**
     * График из условий договора
     */
    private function generateFromContract(PaymentDocument $document, array $config): array
    {
        if (!$document->source_type || $document->source_type !== Contract::class) {
            throw new \InvalidArgumentException('Документ не привязан к договору');
        }

        $contract = Contract::find($document->source_id);
        if (!$contract) {
            throw new \InvalidArgumentException('Договор не найден');
        }

        // Проверяем, есть ли в договоре условия оплаты
        $paymentTerms = $contract->payment_terms ?? null;

        if (!$paymentTerms) {
            // Используем стандартный график по умолчанию
            return $this->generateDefaultFromContract($contract, $document);
        }

        // Парсим условия оплаты из договора
        if (is_string($paymentTerms)) {
            $paymentTerms = json_decode($paymentTerms, true);
        }

        $scheduleType = $paymentTerms['type'] ?? 'equal';

        return match($scheduleType) {
            'equal' => $this->generateEqualInstallments($document, [
                'installments_count' => $paymentTerms['installments'] ?? 3,
                'start_date' => $contract->contract_date,
                'interval_days' => $paymentTerms['interval_days'] ?? 30,
            ]),
            'percentage' => $this->generatePercentageBased($document, [
                'percentages' => $paymentTerms['percentages'] ?? [30, 40, 30],
                'start_date' => $contract->contract_date,
                'interval_days' => $paymentTerms['interval_days'] ?? 30,
            ]),
            default => $this->generateDefaultFromContract($contract, $document),
        };
    }

    /**
     * График по умолчанию из договора
     */
    private function generateDefaultFromContract(Contract $contract, PaymentDocument $document): array
    {
        // Стандартный график: аванс 30%, промежуточный 50%, финальный 20%
        return $this->generatePercentageBased($document, [
            'percentages' => [30, 50, 20],
            'start_date' => $contract->contract_date ?? now(),
            'interval_days' => $contract->payment_terms_days ?? 30,
        ]);
    }

    /**
     * Обновить график платежей
     */
    public function update(PaymentDocument $document, array $config): Collection
    {
        DB::beginTransaction();

        try {
            // Удаляем старый график (только pending платежи)
            PaymentSchedule::where('payment_document_id', $document->id)
                ->where('status', 'pending')
                ->delete();

            // Генерируем новый
            $schedules = $this->generate($document, $config);

            DB::commit();
            return $schedules;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Отметить платеж графика как оплаченный
     */
    public function markPaid(PaymentSchedule $schedule, float $amount, ?int $transactionId = null): PaymentSchedule
    {
        if ($schedule->status === 'paid') {
            throw new \DomainException('Платеж уже оплачен');
        }

        $schedule->status = 'paid';
        $schedule->paid_amount = $amount;
        $schedule->paid_at = now();
        $schedule->payment_transaction_id = $transactionId;
        $schedule->save();

        Log::info('payment_schedule.marked_paid', [
            'schedule_id' => $schedule->id,
            'payment_document_id' => $schedule->payment_document_id,
            'amount' => $amount,
        ]);

        return $schedule;
    }

    /**
     * Получить просроченные платежи
     */
    public function getOverdue(int $organizationId): Collection
    {
        return PaymentSchedule::whereHas('paymentDocument', function($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            })
            ->where('status', 'pending')
            ->where('due_date', '<', Carbon::now())
            ->with(['paymentDocument'])
            ->orderBy('due_date', 'asc')
            ->get();
    }

    /**
     * Получить предстоящие платежи
     */
    public function getUpcoming(int $organizationId, int $days = 7): Collection
    {
        return PaymentSchedule::whereHas('paymentDocument', function($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            })
            ->where('status', 'pending')
            ->whereBetween('due_date', [Carbon::now(), Carbon::now()->addDays($days)])
            ->with(['paymentDocument'])
            ->orderBy('due_date', 'asc')
            ->get();
    }

    /**
     * Рассчитать прогноз платежей
     */
    public function calculateForecast(int $organizationId, int $months = 6): array
    {
        $startDate = Carbon::now()->startOfMonth();
        $endDate = (clone $startDate)->addMonths($months);

        $schedules = PaymentSchedule::whereHas('paymentDocument', function($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            })
            ->where('status', 'pending')
            ->whereBetween('due_date', [$startDate, $endDate])
            ->get();

        $forecast = [];

        for ($i = 0; $i < $months; $i++) {
            $monthStart = (clone $startDate)->addMonths($i);
            $monthEnd = (clone $monthStart)->endOfMonth();

            $monthSchedules = $schedules->filter(function($schedule) use ($monthStart, $monthEnd) {
                return $schedule->due_date >= $monthStart && $schedule->due_date <= $monthEnd;
            });

            $forecast[] = [
                'month' => $monthStart->format('Y-m'),
                'month_label' => $monthStart->translatedFormat('F Y'),
                'count' => $monthSchedules->count(),
                'total_amount' => $monthSchedules->sum('amount'),
                'payments' => $monthSchedules->values(),
            ];
        }

        return $forecast;
    }

    /**
     * Автоматическое создание графика при создании счета из договора
     */
    public function autoGenerateForInvoice(PaymentDocument $document): ?Collection
    {
        // Проверяем, что документ связан с договором
        if (!$document->source_type || $document->source_type !== Contract::class) {
            return null;
        }

        $contract = Contract::find($document->source_id);
        if (!$contract) {
            return null;
        }

        // Проверяем настройки автогенерации
        $autoGenerate = config('payments.auto_generate_schedules', true);
        if (!$autoGenerate) {
            return null;
        }

        try {
            return $this->generate($document, [
                'schedule_type' => 'from_contract',
            ]);
        } catch (\Exception $e) {
            Log::error('payment_schedule.auto_generate_failed', [
                'document_id' => $document->id,
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Получить шаблоны графиков для организации
     */
    public function getTemplates(int $organizationId): array
    {
        return [
            [
                'id' => 'equal_3',
                'name' => 'Равными платежами (3 платежа)',
                'description' => 'График из 3 равных платежей каждые 30 дней',
                'config' => [
                    'schedule_type' => 'equal_installments',
                    'installments_count' => 3,
                    'interval_days' => 30,
                ],
            ],
            [
                'id' => 'advance_30',
                'name' => 'Аванс 30%',
                'description' => 'Аванс 30%, промежуточный 50%, финальный 20%',
                'config' => [
                    'schedule_type' => 'percentage_based',
                    'percentages' => [30, 50, 20],
                    'interval_days' => 30,
                ],
            ],
            [
                'id' => 'advance_50',
                'name' => 'Аванс 50%',
                'description' => 'Аванс 50%, финальный 50%',
                'config' => [
                    'schedule_type' => 'percentage_based',
                    'percentages' => [50, 50],
                    'interval_days' => 30,
                ],
            ],
            [
                'id' => 'monthly',
                'name' => 'Ежемесячно',
                'description' => 'Равные ежемесячные платежи',
                'config' => [
                    'schedule_type' => 'equal_installments',
                    'installments_count' => 12,
                    'interval_days' => 30,
                ],
            ],
            [
                'id' => 'quarterly',
                'name' => 'Ежеквартально',
                'description' => 'Равные квартальные платежи',
                'config' => [
                    'schedule_type' => 'equal_installments',
                    'installments_count' => 4,
                    'interval_days' => 90,
                ],
            ],
        ];
    }
}

