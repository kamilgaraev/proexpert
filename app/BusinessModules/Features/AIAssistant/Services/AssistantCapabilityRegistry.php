<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimateCompositionIntent;

class AssistantCapabilityRegistry
{
    private readonly AssistantDomainCatalog $domainCatalog;

    public function __construct(?AssistantDomainCatalog $domainCatalog = null)
    {
        $this->domainCatalog = $domainCatalog ?? new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
    }

    public function all(): array
    {
        $capabilities = $this->legacyCapabilities();
        $aliases = ['finance' => 'payments', 'schedule' => 'schedules'];
        foreach ($this->domainCatalog->all() as $definition) {
            $id = $aliases[$definition->domain] ?? $definition->domain;
            $index = array_search($id, array_column($capabilities, 'id'), true);
            $route = $definition->domain === 'knowledge' ? '/knowledge-hub' : (preg_replace('/\/\{[^}]+\}/', '', $definition->navigation) ?? $definition->navigation);
            if ($index !== false) {
                $capabilities[$index]['domain'] = $definition->domain;
                $capabilities[$index]['module'] = $definition->module;
                $capabilities[$index]['entity_types'] = $definition->entityTypes;
                $capabilities[$index]['read_permissions'] = $definition->permissions;
                $capabilities[$index]['keywords'] = array_values(array_unique([...$capabilities[$index]['keywords'], ...$this->domainKeywords($definition->domain)]));

                continue;
            }
            $capabilities[] = $this->makeCapability(
                $id, trans_message('ai_assistant.capability_'.$definition->domain),
                $this->domainKeywords($definition->domain), $definition->permissions, [],
                $route === '' ? [] : [['type' => 'navigate', 'label' => trans_message('ai_assistant.capability_open', ['module' => trans_message('ai_assistant.capability_'.$definition->domain)]), 'target' => ['route' => $route], 'required_permissions' => $definition->permissions]]
            ) + ['module' => $definition->module, 'entity_types' => $definition->entityTypes];
        }
        if (! in_array('measurement_units', array_column($capabilities, 'id'), true)) {
            $capabilities[] = $this->makeCapability(
                'measurement_units', trans_message('ai_assistant.capability_measurement_units'),
                ['единиц измерен', 'единицу измерен', 'единицы измерен', 'ед измерен', 'measurement unit'],
                ['measurement_units.view'], [],
                [['type' => 'navigate', 'label' => trans_message('ai_assistant.capability_open', ['module' => trans_message('ai_assistant.capability_measurement_units')]), 'target' => ['route' => '/catalogs/measurement-units'], 'required_permissions' => ['measurement_units.view']]]
            );
        }

        return $capabilities;
    }

    public function domainKeywords(string $domain): array
    {
        $domain = $this->intentDomain($domain);
        return match ($domain) {
            'projects' => ['проект', 'объект', 'стройк'],
            'estimates' => ['смет', 'расценк', 'estimate'],
            'contracts' => ['договор', 'контракт', 'подрядчик'],
            'finance' => ['платеж', 'оплат', 'финанс', 'счет', 'счёт', 'задолж', 'долг'],
            'procurement' => ['закуп', 'снабжен', 'поставщик', 'заказ поставщик'],
            'schedule' => ['график', 'срок', 'критический путь'],
            'warehouse' => ['склад', 'остатк', 'материал'],
            'site_requests' => ['заявка с объекта', 'заявки с объекта', 'заявку с объекта', 'заявка на материал', 'заявка на техник', 'заявка на персонал'],
            'works' => ['выполненн работ', 'выполненные работы', 'объем работ', 'объём работ'],
            'acts' => ['акты', 'акт выполн', 'акт прием', 'акт приём'],
            'people' => ['сотрудник', 'персонал', 'прораб', 'пользовател', 'бригада'],
            'production_labor' => ['выработк', 'производственн наряд', 'табел', 'начислен'],
            'time_tracking' => ['учет времени', 'учёт времени', 'трудозатрат', 'рабочее время', 'рабочих часов'],
            'machinery' => ['техник', 'оборудован', 'машин', 'простой', 'топлив', 'механизм'],
            'quality' => ['качеств', 'дефект'],
            'documents' => ['документ', 'исполнительн', 'документац'],
            'safety' => ['безопасност', 'инцидент', 'нарушен', 'наряд допуск', 'инструктаж'],
            'change_management' => ['изменения проект', 'изменений проект', 'запрос на изменен', 'rfi', 'вариац', 'претензи'],
            'handover_acceptance' => ['приемк', 'приёмк', 'передач', 'замечан', 'чек лист', 'handover'],
            'crm' => ['crm', 'срм', 'лид', 'сделк', 'воронк', 'контакт'],
            'commercial_processes' => ['коммерческ предложен', 'коммерческ', 'кп'],
            'knowledge' => ['база знаний', 'базы знаний', 'базе знаний', 'статья', 'статьи', 'инструкци'],
            'design' => ['пир', 'проектирован', 'проектная документац', 'пакет проект', 'модель bim', 'замечания проект', ...AssistantBimModelReader::keywords()],
            'budgeting' => ['бюджетирован', 'бюджет', 'бдр', 'бддс', 'цфо', 'кассовый разрыв', 'управленческ'],
            'tenders' => ['тендер', 'конкурс', 'тендерная заявк'],
            'advance_accounting' => ['подотчет', 'подотчёт', 'авансовый отчет', 'авансовый отчёт'],
            'workforce' => ['кадров', 'сотрудник', 'табель', 'посещаемост', 'трудов ресурс'],
            'workforce_hr' => ['кадров', 'личные данные сотрудник', 'кадровый учет', 'кадровый учёт'],
            'workforce_payroll' => ['зарплат', 'расчет зарплат', 'расчёт зарплат', 'начислен', 'фот'],
            'brigades' => ['бригад', 'заявка бригад', 'назначение бригад'],
            'materials' => ['каталог материал', 'карточка материал'],
            'work_types' => ['вид работ', 'виды работ', 'каталог работ'],
            'measurement_units' => ['единиц измерен', 'единицу измерен', 'единицы измерен', 'ед измерен', 'measurement unit'],
            'video_monitoring' => ['видеонаблюден', 'камер', 'события камер'],
            'one_c_exchange' => ['обмен 1с', 'обмен с 1с', 'интеграция 1с'],
            'report_templates' => ['шаблон отчет', 'шаблон отчёт'],
            'reports', 'report_cards' => ['отчет', 'отчёт', 'выгрузк', 'отчетност', 'отчётност'],
            'normative' => ['норматив', 'нормы', 'сборник', 'индекс расценок'],
            'sales_supplier_catalog' => ['поставщик', 'каталог поставщик'],
            'contractor_marketplace' => ['подрядчик', 'биржа', 'предложение подряд', 'профиль подряд'],
            'site_requests' => ['заявка с объект', 'заявка на материал', 'полевая заявк'],
            'legal_business' => ['юридическ', 'архив договор', 'правов', 'юридический документ'],
            'mdm', 'mdm_requests' => ['справочник', 'объединение карточ', 'дубликат', 'нормализац'],
            'holding', 'core_organizations' => ['организац', 'холдинг', 'дочерн', 'компания'],
            'core_commercial' => ['подписк', 'тариф', 'счет организации', 'счёт организации'],
            'core_journals' => ['журнал', 'журнал работ', 'запись журнала'],
            'core_notifications' => ['уведомлен', 'напоминан'],
            default => array_values(array_filter(array_map(static fn (string $type): ?string =>
                AssistantExtendedDomainRegistry::values('entityLabels')[$type] ?? null, $this->domainCatalog->definition($domain)?->entityTypes ?? []))),
        };
    }

    private function intentDomain(string $domain): string
    {
        return match ($domain) {
            'design_detail' => 'design', 'design_normative', 'normative_lookup', 'normative_prices', 'core_normative' => 'normative',
            'procurement_business' => 'procurement', 'crm_business' => 'crm', 'commercial_proposals_business', 'presale_estimates' => 'commercial_processes',
            'operations_warehouse' => 'warehouse', 'operations_assets', 'operations_machinery' => 'machinery', 'operations_quality' => 'quality',
            'operations_safety', 'operations_safety_medical' => 'safety', 'operations_schedule', 'core_schedule' => 'schedule',
            'operations_site_requests' => 'site_requests', 'executive_business', 'core_files' => 'documents', 'handover_business' => 'handover_acceptance',
            'change_business' => 'change_management', 'generation_cards', 'generation_finance', 'core_estimates' => 'estimates',
            'core_contracts' => 'contracts', 'core_works' => 'works', 'core_catalog' => 'materials', 'core_payments' => 'finance',
            'core_projects' => 'projects', 'core_integration' => 'one_c_exchange', 'core_knowledge' => 'knowledge', 'core_time' => 'time_tracking',
            'holding_site', 'holding_templates', 'holding_reports', 'holding_finance', 'core_holding' => 'holding',
            default => $domain,
        };
    }

    private function legacyCapabilities(): array
    {
        return [
            $this->makeCapability(
                'projects',
                'Проекты',
                ['проект', 'проекты', 'объект', 'объекты', 'стройка'],
                ['projects.view'],
                ['projects.create', 'projects.edit'],
                [
                    [
                        'type' => 'navigate',
                        'label' => 'Открыть проекты',
                        'target' => ['route' => '/projects'],
                        'required_permissions' => ['projects.view'],
                    ],
                    [
                        'type' => 'navigate',
                        'label' => 'Показать карту проектов',
                        'target' => ['route' => '/projects/map'],
                        'required_permissions' => ['projects.view'],
                    ],
                ]
            ),
            $this->makeCapability(
                'contracts',
                'Контракты',
                ['контракт', 'контракты', 'договор', 'договоры', 'подрядчик'],
                ['contracts.view', 'admin.contracts.view'],
                ['contracts.create', 'contracts.edit', 'admin.contracts.edit'],
                [
                    [
                        'type' => 'navigate',
                        'label' => 'Открыть контракты',
                        'target' => ['route' => '/contracts'],
                        'required_permissions' => ['contracts.view', 'admin.contracts.view'],
                    ],
                ]
            ),
            $this->makeCapability(
                'reports',
                'Отчеты',
                ['отчет', 'отчеты', 'сводк', 'аналитика', 'финанс', 'финансы', 'бюджет', 'затрат', 'расход', 'прибыл', 'рентабельн', 'маржинальн', 'pdf', 'excel'],
                ['reports.view', 'admin.reports.view'],
                ['reports.create', 'reports.edit', 'reports.export'],
                [
                    [
                        'type' => 'navigate',
                        'label' => 'Открыть отчеты',
                        'target' => ['route' => '/reports'],
                        'required_permissions' => ['reports.view', 'admin.reports.view'],
                    ],
                ]
            ),
            $this->makeCapability(
                'warehouse',
                'Склад',
                ['склад', 'остатки', 'поставка', 'материал', 'материалы'],
                ['warehouse.view', 'materials.view'],
                ['warehouse.manage_stock', 'materials.edit'],
                [
                    [
                        'type' => 'navigate',
                        'label' => 'Открыть склад',
                        'target' => ['route' => '/warehouse'],
                        'required_permissions' => ['warehouse.view'],
                    ],
                ]
            ),
            $this->makeCapability(
                'payments',
                'Платежи',
                ['платеж', 'платежи', 'оплат', 'счет', 'счета', 'счёт', 'счёта', 'выплат', 'долг', 'задолж', 'согласование'],
                ['payments.invoice_view', 'payments.transaction_view', 'admin.payments.view'],
                ['payments.settings_manage', 'payments.reconciliation_perform'],
                [
                    [
                        'type' => 'navigate',
                        'label' => 'Открыть платежные документы',
                        'target' => ['route' => '/payments/documents'],
                        'required_permissions' => ['payments.invoice_view', 'admin.payments.view'],
                    ],
                    [
                        'type' => 'navigate',
                        'label' => 'Открыть согласования',
                        'target' => ['route' => '/payments/approvals'],
                        'required_permissions' => ['payments.invoice_view', 'admin.payments.view'],
                    ],
                ]
            ),
            $this->makeCapability(
                'schedules',
                'Графики',
                ['график', 'графики', 'срок', 'сроки', 'этап', 'критический путь'],
                ['schedule-management.view'],
                ['schedule-management.create', 'schedule-management.edit'],
                [
                    [
                        'type' => 'navigate',
                        'label' => 'Открыть графики',
                        'target' => ['route' => '/schedules'],
                        'required_permissions' => ['schedule-management.view'],
                    ],
                    [
                        'type' => 'navigate',
                        'label' => 'Открыть календарь графиков',
                        'target' => ['route' => '/schedules/calendar'],
                        'required_permissions' => ['schedule-management.view'],
                    ],
                ]
            ),
            $this->makeCapability(
                'procurement',
                'Закупки',
                ['закупка', 'закупки', 'снабжение', 'поставка', 'заявка'],
                ['procurement.view'],
                ['procurement.manage', 'procurement.purchase_requests.approve'],
                [
                    [
                        'type' => 'navigate',
                        'label' => 'Открыть закупки',
                        'target' => ['route' => '/procurement/contracts'],
                        'required_permissions' => ['procurement.view'],
                    ],
                ]
            ),
            $this->makeCapability(
                'notifications',
                'Уведомления',
                ['уведомление', 'уведомления', 'сообщение', 'напоминание', 'эскалация'],
                ['admin.notifications.view', 'projects.view'],
                ['projects.edit'],
                [
                    [
                        'type' => 'navigate',
                        'label' => 'Открыть уведомления',
                        'target' => ['route' => '/notifications'],
                        'required_permissions' => ['admin.notifications.view', 'projects.view'],
                    ],
                ]
            ),
        ];
    }

    public function match(string $query, array $context = [], ?string $goal = null): ?array
    {
        $normalizedQuery = str_replace('ё', 'е', mb_strtolower(trim($query)));
        $normalizedGoal = mb_strtolower(trim((string) $goal));
        $sourceModule = mb_strtolower(trim((string) ($context['source_module'] ?? '')));
        $sourceRoute = mb_strtolower(trim((string) ($context['source_route'] ?? '')));
        $assistantPath = mb_strtolower(trim((string) ($context['ui_state']['assistant_path'] ?? '')));

        $capabilities = $this->all();
        $selectedEstimate = $this->hasEstimateContext($context);
        if (AssistantEstimateCompositionIntent::matches($query, $selectedEstimate)) {
            foreach ($capabilities as $capability) {
                if ($capability['domain'] === 'estimates') {
                    return $capability;
                }
            }
        }
        $explicitDomains = [];
        foreach ($capabilities as $capability) {
            $domain = (string) $capability['domain'];
            $keywords = $domain === 'measurement_units' ? $capability['keywords'] : $this->domainKeywords($domain);
            foreach ($keywords as $keyword) {
                if ($this->matchesKeyword($normalizedQuery, $keyword)) {
                    $explicitDomains[] = $domain;
                    break;
                }
            }
        }
        $explicitIntentDomains = array_values(array_unique(array_map($this->intentDomain(...), $explicitDomains)));
        if ($selectedEstimate && ($explicitIntentDomains === [] || array_diff($explicitIntentDomains, ['estimates', 'projects']) === [] && in_array('estimates', $explicitIntentDomains, true))) {
            foreach ($capabilities as $capability) {
                if ($capability['domain'] === 'estimates') {
                    return $capability;
                }
            }
        }
        $bestMatch = null;
        $bestScore = 0;
        foreach ($capabilities as $capability) {
            $score = 0;
            $capabilityId = (string) $capability['id'];
            $domain = (string) $capability['domain'];
            if ($explicitDomains !== [] && ! in_array($domain, $explicitDomains, true)) {
                continue;
            }
            if ($this->intentDomain($domain) === 'projects' && array_diff($explicitIntentDomains, ['projects']) !== []) {
                continue;
            }
            $module = str_replace('_', '-', (string) ($capability['module'] ?? $capabilityId));
            if ($sourceModule !== '' && (str_contains($sourceModule, $capabilityId) || ($module !== '' && str_contains(str_replace('_', '-', $sourceModule), $module)))) {
                $score += 5;
            }
            if ($this->routeMatchesCapability($sourceRoute, $capabilityId)) {
                $score += 7;
            }
            if ($this->routeMatchesCapability($assistantPath, $capabilityId)) {
                $score += 5;
            }
            if ($normalizedGoal !== '' && str_contains($normalizedGoal, $capabilityId)) {
                $score += 4;
            }
            $specificity = 0;
            foreach ($capability['keywords'] as $keyword) {
                if ($this->matchesKeyword($normalizedQuery, $keyword)) {
                    $score += 2;
                    $specificity = max($specificity, mb_strlen($keyword));
                }
            }
            $score += min(20, $specificity);
            if ($explicitDomains !== []) {
                $score += 10;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $capability;
            }
        }

        return $bestScore > 0 ? $bestMatch : null;
    }

    private function routeMatchesCapability(string $route, string $capabilityId): bool
    {
        if ($route === '') {
            return false;
        }

        $domain = $this->intentDomain($this->domainCatalog->definition($capabilityId)?->domain ?? $capabilityId);
        $routeCapability = match ($domain) { 'finance' => 'payments', 'schedule' => 'schedules', default => $domain };
        return match ($routeCapability) {
            'projects' => str_contains($route, '/projects') && ! str_contains($route, '/schedules') && ! str_contains($route, '/estimates'),
            'contracts' => str_contains($route, '/contracts') && ! str_contains($route, '/procurement'),
            'reports' => str_contains($route, '/reports'),
            'warehouse' => str_contains($route, '/warehouse'),
            'payments' => str_contains($route, '/payments'),
            'schedules' => str_contains($route, '/schedules'),
            'procurement' => str_contains($route, '/procurement'),
            'notifications' => str_contains($route, '/notifications'),
            default => $this->domainRouteMatches($route, $capabilityId),
        };
    }

    private function domainRouteMatches(string $route, string $capabilityId): bool
    {
        if ($capabilityId === 'measurement_units') {
            return str_contains($route, '/measurement-units');
        }
        $definition = $this->domainCatalog->definition($capabilityId);
        if ($definition === null) {
            return false;
        }
        $base = explode('/{', $definition->navigation)[0];

        return $base !== '' && str_contains($route, $base);
    }

    public function hasEstimateContext(array $context): bool
    {
        if (($context['last_capability'] ?? null) === 'estimates' || $this->isPositiveIdentifier($context['selected_estimate_id'] ?? null)) {
            return true;
        }
        $selection = is_array($context['selected_estimate'] ?? null) ? $context['selected_estimate'] : [];
        if ($this->isPositiveIdentifier($selection['estimate_id'] ?? $selection['id'] ?? null)) {
            return true;
        }
        foreach (['entity_refs', 'entity_references'] as $key) {
            foreach (is_array($context[$key] ?? null) ? $context[$key] : [] as $reference) {
                if (is_array($reference) && ($reference['type'] ?? $reference['entity_type'] ?? null) === 'estimate' && $this->isPositiveIdentifier($reference['id'] ?? $reference['entity_id'] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matchesKeyword(string $query, string $keyword): bool
    {
        $keyword = str_replace('ё', 'е', $keyword);

        return $keyword !== '' && preg_match('/(?:^|[^\\p{L}\\p{N}])'.preg_quote($keyword, '/').'/u', $query) === 1;
    }

    private function isPositiveIdentifier(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0;
    }

    private function makeCapability(
        string $id,
        string $label,
        array $keywords,
        array $readPermissions,
        array $writePermissions,
        array $actions
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'domain' => $id,
            'keywords' => $keywords,
            'read_permissions' => $readPermissions,
            'write_permissions' => $writePermissions,
            'actions' => $actions,
        ];
    }
}
