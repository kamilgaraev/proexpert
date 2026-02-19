<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Illuminate\Support\Facades\Log;

class VoiceCommandService
{
    private const SUPPORTED_COMMANDS = [
        'delete_empty'    => 'Удалить все пустые строки',
        'apply_index'     => 'Применить индекс к разделу',
        'filter_section'  => 'Оставить только указанный раздел',
        'mark_anomalies'  => 'Пометить аномальные цены',
        'recalculate'     => 'Пересчитать итоги',
    ];

    public function __construct(
        private readonly LLMProviderInterface $llmProvider
    ) {}

    public function parseCommand(string $voiceText, array $contextRows = []): array
    {
        $contextSummary = '';
        if (!empty($contextRows)) {
            $sectionNames = collect($contextRows)
                ->where('is_section', true)
                ->pluck('item_name')
                ->take(10)
                ->implode(', ');
            $contextSummary = "Доступные разделы: {$sectionNames}.";
        }

        $commandList = implode("\n", array_map(
            fn($k, $v) => "- {$k}: {$v}",
            array_keys(self::SUPPORTED_COMMANDS),
            self::SUPPORTED_COMMANDS
        ));

        $messages = [
            [
                'role'    => 'system',
                'content' => "Ты — ассистент для работы со сметами. Распознай команду пользователя и верни JSON с полями: command (одно из: delete_empty, apply_index, filter_section, mark_anomalies, recalculate), params (объект с параметрами команды). Список команд:\n{$commandList}",
            ],
            [
                'role'    => 'user',
                'content' => "Команда: \"{$voiceText}\". {$contextSummary}\nВерни ТОЛЬКО корректный JSON без пояснений.",
            ],
        ];

        try {
            $response = $this->llmProvider->chat($messages, [
                'temperature' => 0.1,
                'max_tokens'  => 200,
            ]);

            $content = $response['content'] ?? '';
            if (empty($content)) {
                return $this->unknownCommand($voiceText);
            }

            if (preg_match('/\{.*\}/s', $content, $matches)) {
                $parsed = json_decode($matches[0], true);
                if (json_last_error() === JSON_ERROR_NONE && isset($parsed['command'])) {
                    Log::info("[VoiceCommand] Parsed: '{$voiceText}' → {$parsed['command']}", $parsed['params'] ?? []);
                    return [
                        'success'     => true,
                        'command'     => $parsed['command'],
                        'params'      => $parsed['params'] ?? [],
                        'raw_text'    => $voiceText,
                        'description' => self::SUPPORTED_COMMANDS[$parsed['command']] ?? $parsed['command'],
                    ];
                }
            }

            Log::warning("[VoiceCommand] Could not parse LLM response for: '{$voiceText}'", ['content' => $content]);
            return $this->unknownCommand($voiceText);

        } catch (\Throwable $e) {
            Log::error('[VoiceCommand] LLM call failed: ' . $e->getMessage());
            return $this->unknownCommand($voiceText);
        }
    }

    public function executeCommand(string $command, array $params, array $rows): array
    {
        return match ($command) {
            'delete_empty'   => $this->deleteEmptyRows($rows),
            'mark_anomalies' => $this->markAnomalyRows($rows),
            'filter_section' => $this->filterBySection($rows, $params['section'] ?? null),
            'recalculate'    => $this->recalculateTotals($rows),
            default          => $rows,
        };
    }

    private function deleteEmptyRows(array $rows): array
    {
        return array_values(array_filter($rows, function ($row) {
            if ($row['is_section'] ?? false) {
                return true;
            }
            $price = (float)($row['unit_price'] ?? 0);
            $qty   = (float)($row['quantity'] ?? 0);
            $total = (float)($row['current_total_amount'] ?? 0);
            return $price > 0 || $qty > 0 || $total > 0;
        }));
    }

    private function markAnomalyRows(array $rows): array
    {
        foreach ($rows as &$row) {
            if (!empty($row['anomaly'])) {
                $row['_highlighted'] = true;
            }
        }
        return $rows;
    }

    private function filterBySection(array $rows, ?string $sectionName): array
    {
        if ($sectionName === null) {
            return $rows;
        }

        $normalized = mb_strtolower($sectionName);
        $insideSection = false;
        $result = [];

        foreach ($rows as $row) {
            if ($row['is_section'] ?? false) {
                $insideSection = str_contains(mb_strtolower((string)($row['item_name'] ?? '')), $normalized);
                if ($insideSection) {
                    $result[] = $row;
                }
                continue;
            }
            if ($insideSection) {
                $result[] = $row;
            }
        }

        return $result;
    }

    private function recalculateTotals(array $rows): array
    {
        foreach ($rows as &$row) {
            if ($row['is_section'] ?? false) {
                continue;
            }
            $qty   = (float)($row['quantity'] ?? 0);
            $price = (float)($row['unit_price'] ?? 0);
            if ($qty > 0 && $price > 0) {
                $row['current_total_amount'] = round($qty * $price, 2);
            }
        }
        return $rows;
    }

    private function unknownCommand(string $voiceText): array
    {
        return [
            'success'  => false,
            'command'  => 'unknown',
            'params'   => [],
            'raw_text' => $voiceText,
            'message'  => "Команда не распознана: \"{$voiceText}\"",
        ];
    }
}
