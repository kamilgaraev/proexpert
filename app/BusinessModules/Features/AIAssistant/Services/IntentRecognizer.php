<?php

namespace App\BusinessModules\Features\AIAssistant\Services;

class IntentRecognizer
{
    /**
     * Паттерны для распознавания намерений пользователя
     * Чем раньше в массиве - тем выше приоритет при совпадении
     */
    protected array $patterns = [
        // Статус проектов
        'project_status' => [
            'статус проект',
            'как идут проект',
            'как дела',
            'что происходит',
            'активные проекты',
            'текущие проекты',
            'покажи проект',
            'список проект',
            'какие проекты',
            'все проекты',
            'проекты в работе',
            'незавершенные проект',
            'open проект',
            'проекты на сегодня',
            'обзор проект',
            'состояние проект',
            'по проектам',
        ],
        
        // Бюджет и финансы проектов
        'project_budget' => [
            'бюджет проект',
            'сколько потрачено',
            'затраты по проект',
            'расходы проект',
            'осталось денег',
            'финанс проект',
            'стоимость проект',
            'смета проект',
            'план факт',
            'перерасход',
            'экономия',
            'освоение бюджет',
            'деньги на проект',
            'траты',
            'издержки',
            'расход средств',
        ],
        
        // Риски проектов
        'project_risks' => [
            'риск',
            'проблем',
            'задержк',
            'срывается',
            'отстава',
            'зона риска',
            'в опасности',
            'красная зона',
            'проблемные проект',
            'срыв срок',
            'не успева',
            'тревог',
            'критическ',
            'угроз',
            'warning',
            'alert',
            'внимани',
            'под вопросом',
        ],
        
        // Поиск контрактов
        'contract_search' => [
            'контракт',
            'договор',
            'найди контракт',
            'покажи договор',
            'найди договор',
            'список контракт',
            'все договор',
            'контракт с',
            'договор с',
            'соглашени',
            'подряд',
            'контракты по',
            'договоренност',
        ],
        
        // Детали конкретного контракта
        'contract_details' => [
            'контракт №',
            'договор №',
            'детали контракт',
            'подробности договор',
            'информация о контракт',
            'расскажи про контракт',
            'что по контракт',
            'условия договор',
            'параметры контракт',
        ],
        
        // Остатки материалов
        'material_stock' => [
            // Базовые запросы
            'остаток материал',      // Покрывает все окончания
            'остатки материал',
            'остаток',
            'остатки',
            'запас материал',
            'запасы материал',
            'запас',
            'запасы',
            'материал',
            'материалов',
            'материалы',
            
            // На складе
            'на складе',
            'склад',
            'что на складе',
            
            // Количество
            'сколько материал',
            'сколько осталось',
            'хватит',
            'хватает',
            
            // Проблемы с материалами
            'заканчива',
            'кончается',
            'кончаются',
            'мало материал',
            'нехватка',
            'дефицит',
            'нет материал',
            'не хватает',
            'закончились',
            
            // Складской учет
            'складские остатки',
            'инвентарь',
            'инвентаризация',
            'inventory',
            'stock',
            
            // Наличие
            'наличие материал',
            'в наличии',
            'есть ли материал',
            
            // Уровни запасов
            'низкие остатки',
            'критичные остатки',
            'критический уровень',
            'минимальный запас',
        ],
        
        // Прогноз потребности в материалах
        'material_forecast' => [
            'прогноз материал',
            'потребность',
            'закупить',
            'заказать',
            'нужно купить',
            'необходим',
            'что купить',
            'план закуп',
            'заявка на материал',
            'forecast',
            'планируем',
            'понадобится',
            'требуется материал',
            'нужны материал',
        ],
        
        // Общий анализ и аналитика
        'analytics' => [
            'анализ',
            'аналитика',
            'отчет',
            'статистика',
            'показател',
            'метрик',
            'kpi',
            'эффективность',
            'производительность',
            'сводка',
            'дашборд',
            'dashboard',
            'overview',
        ],
        
        // Информация о текущем пользователе
        'user_info' => [
            'кто я',
            'как меня зовут',
            'мое имя',
            'моя роль',
            'мои права',
            'обо мне',
            'кто разговаривает',
            'с кем ты',
            'кто здесь',
            'мой профиль',
            'мои данные',
        ],
        
        // Информация о сотрудниках
        'team_info' => [
            'сотрудник',
            'команда',
            'коллег',
            'работник',
            'персонал',
            'кто работает',
            'список сотрудник',
            'наша команда',
            'кадры',
            'staff',
            'team',
            'пользовател',
            'кто в системе',
        ],
        
        // Информация об организации
        'organization_info' => [
            'о компании',
            'о нашей компании',
            'о организации',
            'расскажи о компании',
            'наша организация',
            'о нас',
            'информация о компании',
            'компания',
            'организация',
        ],
        
        // Помощь и возможности
        'help' => [
            'помощь',
            'help',
            'что ты умеешь',
            'что можешь',
            'твои возможности',
            'как работаешь',
            'что делаешь',
            'команды',
            'инструкция',
            'справка',
            'как пользоваться',
            'что спросить',
        ],
        
        // Общие вопросы
        'general' => [],
    ];

    /**
     * Приоритеты интентов (чем больше число, тем выше приоритет при конфликте)
     */
    protected array $intentPriority = [
        'contract_details' => 10,     // Самый специфичный
        'project_risks' => 9,
        'project_budget' => 8,
        'material_forecast' => 7,
        'material_stock' => 6,
        'contract_search' => 5,
        'project_status' => 4,
        'analytics' => 3,
        'user_info' => 7,             // Информация о пользователе
        'team_info' => 6,             // Информация о команде
        'organization_info' => 5,     // Информация об организации
        'help' => 8,                  // Помощь - высокий приоритет
        'general' => 1,               // Самый общий
    ];

    /**
     * Распознает намерение пользователя на основе запроса
     * Использует систему приоритетов при множественных совпадениях
     * 
     * @param string $query Запрос пользователя
     * @param string|null $previousIntent Предыдущий распознанный интент для контекста
     * @return string
     */
    public function recognize(string $query, ?string $previousIntent = null): string
    {
        $query = mb_strtolower($query);
        $matches = [];

        // Проверяем контекстные фразы, которые продолжают предыдущий запрос
        if ($previousIntent && $this->isContextualFollowUp($query)) {
            // Если это контекстная фраза, возвращаем тот же intent
            return $previousIntent;
        }

        // Собираем все совпавшие интенты с их приоритетами
        foreach ($this->patterns as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_strpos($query, $keyword) !== false) {
                    $priority = $this->intentPriority[$intent] ?? 0;
                    
                    // Учитываем длину ключевого слова (длиннее = специфичнее)
                    $keywordLength = mb_strlen($keyword);
                    $score = $priority * 10 + $keywordLength;
                    
                    if (!isset($matches[$intent]) || $matches[$intent] < $score) {
                        $matches[$intent] = $score;
                    }
                    
                    // Прерываем внутренний цикл, переходим к следующему интенту
                    break;
                }
            }
        }

        // Если нет совпадений, возвращаем general
        if (empty($matches)) {
            return 'general';
        }

        // Возвращаем интент с наивысшим приоритетом
        arsort($matches);
        return array_key_first($matches);
    }
    
    /**
     * Проверяет, является ли запрос контекстным продолжением предыдущего
     */
    protected function isContextualFollowUp(string $query): bool
    {
        $contextualPhrases = [
            'обо всех',
            'про все',
            'по всем',
            'давай по всем',
            'покажи все',
            'покажи всё',
            'всё',
            'все',
            'ещё',
            'еще',
            'подробнее',
            'детальнее',
            'расскажи ещё',
            'что ещё',
            'а ещё',
            'также',
            'тоже',
        ];
        
        foreach ($contextualPhrases as $phrase) {
            if (mb_strpos($query, $phrase) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Извлекает все параметры из запроса
     */
    public function extractAllParams(string $query): array
    {
        $params = [];
        
        // Извлечение номера проекта
        $projectNumber = $this->extractProjectNumber($query);
        if ($projectNumber) {
            $params['project_number'] = $projectNumber;
        }
        
        // Извлечение названия проекта
        $projectName = $this->extractProjectName($query);
        if ($projectName) {
            $params['project_name'] = $projectName;
        }
        
        // Извлечение номера контракта
        $contractNumber = $this->extractContractNumber($query);
        if ($contractNumber) {
            $params['contract_number'] = $contractNumber;
        }
        
        // Извлечение названия контрагента
        $contractorName = $this->extractContractorName($query);
        if ($contractorName) {
            $params['contractor_name'] = $contractorName;
        }
        
        // Извлечение названия материала
        $materialName = $this->extractMaterialName($query);
        if ($materialName) {
            $params['material_name'] = $materialName;
        }
        
        // Извлечение временного периода
        $period = $this->extractTimePeriod($query);
        if ($period) {
            $params['period'] = $period;
        }
        
        return $params;
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

    /**
     * Извлекает номер проекта из запроса
     */
    public function extractProjectNumber(string $query): ?string
    {
        // Паттерны: "проект #123", "проект №45", "проекта 78"
        if (preg_match('/проект[а-яё\s]*[#№]?\s*(\d+)/ui', $query, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Извлекает номер контракта из запроса
     */
    public function extractContractNumber(string $query): ?string
    {
        // Паттерны с номером контракта
        $patterns = [
            '/(?:договор|контракт)[а-яё\s]*№\s*([\\d\\/\\-]+)/ui',
            '/№\s*([\\d\\/\\-]+)(?:\\s+от)?/ui',
            '/контракт[а-яё\s]*([\\d\\/\\-]+)/ui',
            '/договор[а-яё\s]*([\\d\\/\\-]+)/ui',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $query, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
    
    /**
     * Извлекает название контрагента
     */
    public function extractContractorName(string $query): ?string
    {
        // Паттерны: "с компанией СтройИнвест", "подрядчик АБВ", "контрагент Рога и Копыта"
        $patterns = [
            '/(?:с компанией|с организацией|с|подрядчик|контрагент)\s+([А-ЯЁа-яёA-Za-z0-9\s\-"«»]+?)(?:\s|$|,|\.|\?)/ui',
            '/(?:ООО|ИП|ЗАО|ОАО|АО)\s+[«"]?([^»",\.]+)[»"]?/ui',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $query, $matches)) {
                $name = trim($matches[1]);
                // Убираем слишком короткие или слишком длинные совпадения
                if (mb_strlen($name) >= 3 && mb_strlen($name) <= 100) {
                    return $name;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Извлекает название материала
     */
    public function extractMaterialName(string $query): ?string
    {
        // Паттерны: "материал цемент", "кирпич красный", "арматура 12мм"
        $commonMaterials = [
            'цемент', 'бетон', 'кирпич', 'арматура', 'песок', 'щебень',
            'гипс', 'штукатурка', 'краска', 'гвозди', 'саморезы', 'доски',
            'фанера', 'утеплитель', 'пенопласт', 'минвата', 'плитка', 
            'керамогранит', 'линолеум', 'ламинат', 'обои'
        ];
        
        $query_lower = mb_strtolower($query);
        
        foreach ($commonMaterials as $material) {
            if (mb_strpos($query_lower, $material) !== false) {
                return $material;
            }
        }
        
        // Извлечение из паттернов типа "материал [название]"
        if (preg_match('/материал[а-яё\s]+([а-яёa-z0-9\s\-]+?)(?:\s|$|,|\.|\?)/ui', $query, $matches)) {
            $name = trim($matches[1]);
            if (mb_strlen($name) >= 3 && mb_strlen($name) <= 50) {
                return $name;
            }
        }
        
        return null;
    }
    
    /**
     * Извлекает временной период из запроса
     */
    public function extractTimePeriod(string $query): ?string
    {
        $query_lower = mb_strtolower($query);
        
        // Абсолютные периоды
        $periods = [
            'сегодня' => 'today',
            'вчера' => 'yesterday',
            'на сегодня' => 'today',
            'за сегодня' => 'today',
            'за вчера' => 'yesterday',
            'неделя' => 'week',
            'за неделю' => 'week',
            'текущая неделя' => 'current_week',
            'эта неделя' => 'current_week',
            'месяц' => 'month',
            'за месяц' => 'month',
            'текущий месяц' => 'current_month',
            'этот месяц' => 'current_month',
            'квартал' => 'quarter',
            'за квартал' => 'quarter',
            'год' => 'year',
            'за год' => 'year',
            'текущий год' => 'current_year',
            'этот год' => 'current_year',
        ];
        
        foreach ($periods as $keyword => $period) {
            if (mb_strpos($query_lower, $keyword) !== false) {
                return $period;
            }
        }
        
        // Извлечение конкретных дат
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $query, $matches)) {
            return $matches[0]; // Вернуть дату в формате DD.MM.YYYY
        }
        
        // Извлечение месяца и года
        $months = [
            'январ' => '01', 'феврал' => '02', 'март' => '03', 'апрел' => '04',
            'ма[йя]' => '05', 'июн' => '06', 'июл' => '07', 'август' => '08',
            'сентябр' => '09', 'октябр' => '10', 'ноябр' => '11', 'декабр' => '12',
        ];
        
        foreach ($months as $month => $number) {
            if (preg_match('/' . $month . '[а-яё]*\s+(\d{4})/ui', $query, $matches)) {
                return $number . '.' . $matches[1]; // MM.YYYY
            }
        }
        
        return null;
    }
}


