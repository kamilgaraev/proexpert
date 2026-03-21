<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

class AssistantCapabilityRegistry
{
    public function all(): array
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
                ['отчет', 'отчеты', 'сводка', 'аналитика', 'pdf', 'excel'],
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
                ['платеж', 'платежи', 'оплата', 'счет', 'счета', 'согласование'],
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
        $normalizedQuery = mb_strtolower(trim($query));
        $normalizedGoal = mb_strtolower(trim((string) $goal));
        $sourceModule = mb_strtolower(trim((string) ($context['source_module'] ?? '')));

        $bestMatch = null;
        $bestScore = 0;

        foreach ($this->all() as $capability) {
            $score = 0;

            if ($sourceModule !== '' && str_contains($sourceModule, (string) $capability['id'])) {
                $score += 5;
            }

            if ($normalizedGoal !== '' && str_contains($normalizedGoal, (string) $capability['id'])) {
                $score += 4;
            }

            foreach ($capability['keywords'] as $keyword) {
                if (str_contains($normalizedQuery, $keyword)) {
                    $score += 2;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $capability;
            }
        }

        return $bestScore > 0 ? $bestMatch : null;
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
