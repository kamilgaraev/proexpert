<?php

declare(strict_types=1);

$domains = [
    'estimates' => ['identity' => 'Номер, название, дата и статус сметы', 'totals' => 'Точная сумма сметы и независимая сумма учитываемых позиций', 'positions' => 'Точные количества и суммы позиций сметы'],
    'contracts' => ['status' => 'Текущий статус договора', 'term' => 'Даты начала и окончания договора', 'money' => 'Точная сумма договора и два вида аванса'],
    'finance' => ['status' => 'Текущий статус платёжного документа', 'due' => 'Срок оплаты документа', 'amount' => 'Точная сумма платёжного документа'],
    'works' => ['status' => 'Текущий статус выполненной работы', 'date' => 'Дата выполнения работы', 'quantity' => 'Точный объём выполненной работы'],
    'warehouse' => ['status' => 'Текущий статус складской задачи', 'quantity' => 'Точное количество складского движения', 'identity' => 'Название и код складского актива'],
    'machinery' => ['status' => 'Текущий статус техники', 'inventory' => 'Инвентарный номер техники', 'identity' => 'Название и учётный код техники'],
    'crm' => ['stage' => 'Текущий статус и стадия сделки', 'close' => 'Плановая дата закрытия сделки', 'owner' => 'Ответственный за сделку и его идентификатор'],
];
$scenarios = [];
foreach ($domains as $domain => $focuses) {
    foreach ($focuses as $focus => $description) {
        foreach (['short', 'normal', 'detailed'] as $profile) {
            foreach (['owner', 'project_manager', 'finance_manager', 'viewer'] as $role) {
                $category = $domain.'_'.$focus;
                $id = $category.'-'.$profile.'-'.$role;
                $assertions = [];
                foreach (['rights' => 'current_actual_role_and_entity_scope_enforced', 'leak' => 'no_forbidden_fields_or_unavailable_sources',
                    'unconfirmed_actions' => 'business_fixture_rows_unchanged_during_actual_ask',
                    'factual_amounts' => 'exact_raw_database_values_or_explicit_permission_refusal',
                    'business_quality' => $description] as $key => $expected) {
                    $assertions[$key] = ['expected' => $expected, 'observed' => 'not_run', 'verifier' => '', 'evidence_sha256' => ''];
                }
                $scenarios[] = ['id' => $id, 'category' => $category, 'requested_profile' => $profile,
                    'input' => ['message' => $description, 'context' => ['contract' => 'actual_live_domain', 'domain' => $domain,
                        'field_focus' => $focus, 'role' => $role, 'state' => 'active'],
                        'request_id' => sprintf('10000000-0000-4000-8000-%012d', count($scenarios) + 1)],
                    'outcome' => 'blocked', 'provider_calls' => [], 'successful_cost_micro_rub' => 0,
                    'estimated_minor' => 50, 'approved_minor' => 50, 'charged_minor' => 0, 'assertions' => $assertions];
            }
        }
    }
}

return $scenarios;
