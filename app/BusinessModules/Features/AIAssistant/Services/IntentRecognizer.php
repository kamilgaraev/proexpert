<?php

namespace App\BusinessModules\Features\AIAssistant\Services;

class IntentRecognizer
{
    protected array $patterns = [
        'project_status' => [
            'статус проект',
            'как дела',
            'что происходит',
            'активные проекты',
            'текущие проекты',
        ],
        'project_budget' => [
            'бюджет',
            'сколько потрачено',
            'затраты',
            'расходы',
            'осталось денег',
        ],
        'project_risks' => [
            'риск',
            'проблем',
            'задержк',
            'срывается',
            'отстава',
            'зона риска',
        ],
        'contracts_search' => [
            'контракт',
            'договор',
            'найди контракт',
            'покажи договор',
        ],
        'materials_stock' => [
            'материал',
            'остаток',
            'запас',
            'на складе',
            'хватит',
        ],
        'materials_forecast' => [
            'прогноз материал',
            'потребность',
            'закупить',
            'заказать',
        ],
        'general' => [],
    ];

    public function recognize(string $query): string
    {
        $query = mb_strtolower($query);

        foreach ($this->patterns as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_strpos($query, $keyword) !== false) {
                    return $intent;
                }
            }
        }

        return 'general';
    }

    public function extractProjectName(string $query): ?string
    {
        if (preg_match('/проект[а-яё\s]+[«"]([^»"]+)[»"]/ui', $query, $matches)) {
            return $matches[1];
        }

        if (preg_match('/проект[а-яё\s]+(\S+)/ui', $query, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function extractContractNumber(string $query): ?string
    {
        if (preg_match('/договор[а-яё\s]*№?\s*(\d+[\/-]?\d*)/ui', $query, $matches)) {
            return $matches[1];
        }

        if (preg_match('/контракт[а-яё\s]*№?\s*(\d+[\/-]?\d*)/ui', $query, $matches)) {
            return $matches[1];
        }

        return null;
    }
}

