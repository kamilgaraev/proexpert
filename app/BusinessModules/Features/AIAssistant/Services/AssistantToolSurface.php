<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantToolSurface
{
    private const LINKED_READS = [
        'get_procurement_snapshot' => ['get_project_snapshot'],
        'get_contract_snapshot' => ['get_project_snapshot', 'get_schedule_snapshot', 'get_estimate_answer'],
        'get_schedule_snapshot' => ['get_project_snapshot'],
        'get_material_stock' => ['get_project_snapshot', 'search_materials', 'search_warehouse'],
        'get_estimate_answer' => ['get_project_snapshot', 'resolve_estimate', 'search_estimate_positions',
            'get_estimate_positions', 'get_estimate_financial_snapshot'],
        'get_live_project_financial_evidence' => ['get_project_snapshot', 'get_contract_snapshot', 'get_schedule_snapshot',
            'get_estimate_answer', 'get_material_stock'],
        'get_published_report_financial_evidence' => ['get_project_snapshot', 'get_contract_snapshot', 'get_schedule_snapshot',
            'get_estimate_answer', 'get_material_stock'],
    ];

    public static function names(array $registered, array $plan, bool $selectedEstimate = false): array
    {
        $selected = ['assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation',
            'assistant_domain_discover_capabilities', 'search_projects', 'search_assistant_documents'];
        $capability = $plan['capability'] ?? [];
        $domains = array_filter([(string) ($capability['id'] ?? ''), (string) ($capability['domain'] ?? '')]);
        if ($selectedEstimate) { $domains[] = 'estimates'; }
        $query = mb_strtolower((string) ($plan['request']['message'] ?? ''));
        $entities = $plan['request_understanding']['requested_entities'] ?? [];
        $context = is_array($plan['request']['context'] ?? null) ? $plan['request']['context'] : [];
        $signals = implode(' ', [...$domains, ...(is_array($entities) ? $entities : []),
            (string) ($context['source_module'] ?? ''),
            (string) ($context['ui_state']['assistant_follow_up_query'] ?? ''),
            (string) ($context['ui_state']['assistant_report_focus'] ?? ''), $query]);
        foreach ([
            '/смет|estimat|расцен|бетон/iu' => ['get_estimate_answer', 'search_estimate_positions', 'resolve_estimate',
                'get_estimate_positions', 'get_estimate_financial_snapshot'],
            '/договор|contract/iu' => ['get_contract_snapshot', 'search_contractors'],
            '/склад|warehouse|остат|материал|material/iu' => ['get_material_stock', 'search_warehouse', 'search_materials'],
            '/bim|ifc|бим|модел|design|кондицион|вентил|перекрыти|Ifc/iu' => ['get_bim_model_elements'],
            '/плат[её]ж|финанс|finance|payment|прибыл|бюджет/iu' => ['get_live_project_financial_evidence', 'get_published_report_financial_evidence'],
            '/график|schedule|срок/iu' => ['get_schedule_snapshot'],
            '/снабжен|закуп|procurement/iu' => ['get_procurement_snapshot'],
            '/проект|project/iu' => ['get_project_snapshot'],
            '/сотрудник|персонал|people|workforce/iu' => ['search_users'],
        ] as $pattern => $tools) {
            if (preg_match($pattern, $signals)) {
                array_push($selected, ...$tools);
            }
        }
        if (($plan['request_understanding']['primary_intent'] ?? null) === 'generate_report'
            || preg_match('/pdf|отч[её]т|report|сформируй|выгруз/iu', $signals)) {
            $reports = ['generate_operational_pdf_report'];
            foreach ([
                '/прибыл|profit|рентабел|бюджет/iu' => ['generate_profitability_report'],
                '/выполнен|completion|completed_work/iu' => ['generate_work_completion_report'],
                '/материал|material/iu' => ['generate_material_movements_report'],
                '/подрядчик|contractor|расч[её]т/iu' => ['generate_contractor_settlements_report'],
                '/склад|warehouse|остат/iu' => ['generate_warehouse_stock_report'],
                '/табел|time_tracking|рабоч.*врем/iu' => ['generate_time_tracking_report'],
                '/договор|contract|плат[её]ж|payment/iu' => ['generate_contract_payments_report'],
                '/график|срок|timeline|schedule/iu' => ['generate_project_timelines_report'],
                '/документ|document|rag|норматив/iu' => ['generate_rag_pdf_report'],
            ] as $pattern => $tools) {
                if (preg_match($pattern, $signals)) { array_push($reports, ...$tools); }
            }
            array_push($selected, ...$reports);
        }
        if (($plan['request_understanding']['action_policy'] ?? 'read_only') === 'requires_confirmation') {
            array_push($selected, ...$registered);
        }
        do {
            $before = count(array_unique($selected));
            foreach (array_intersect($registered, $selected) as $name) {
                array_push($selected, ...array_intersect($registered, self::LINKED_READS[$name] ?? []));
            }
            $selected = array_values(array_unique($selected));
        } while (count($selected) > $before);

        return array_values(array_filter($registered, static fn (string $name): bool => in_array($name, $selected, true)));
    }

    public static function compactHints(array $hints): array
    {
        if (count($hints['domains'] ?? []) <= 12) {
            return $hints;
        }
        $hints['domains'] = array_map(static fn (array $domain): array => array_intersect_key($domain,
            array_flip(['domain', 'primary_entity_type', 'entity_type_count'])), $hints['domains']);

        return $hints;
    }
}
