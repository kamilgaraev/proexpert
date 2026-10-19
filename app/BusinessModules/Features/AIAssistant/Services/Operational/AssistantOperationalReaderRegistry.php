<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;

final class AssistantOperationalReaderRegistry
{
    private const STAFF_TOPIC = '/отсутст|отпуск|больнич|командиров|сотрудник|персонал|состав[а-яё]*\s+(?:объект|проект)|кто.*(?:числится|назначен)/iu';

    private const TOPICS = ['/журнал(?![а-яё]*\s+(?:событи|действи))/iu', '/безопасност|охран[а-яё]*\s+труд|происшеств|нарушен/iu',
        '/rfi|вопрос[а-яё]*\s+проектировщик|изменен[а-яё]*.*(?:согласован|решени|влияни)/iu',
        AssistantProjectDesignReader::INTENT_PATTERN,
        '/коммерческ[а-яё]*\s+предложен/iu', '/исполнительн[а-яё]*\s+(?:документ|комплект)|комплект[а-яё]*\s+ид\b/iu',
        '/производствен[а-яё]*\s+наряд|наряд[а-яё]*.*(?:принят|при[её]мк|закрыти|представлен)/iu', '/crm|сделк/iu',
        AssistantProjectReportCatalogReader::INTENT_PATTERN, AssistantProjectExportHistoryReader::INTENT_PATTERN,
        '/материал[а-яё]*.*(?:жд[её]м|ожида|достав)|(?:жд[её]м|ожида).*материал|поставк[а-яё]*.*просроч|просроч[а-яё]*.*(?:поставк|достав)/iu',
        AssistantProjectActReader::INTENT_PATTERN, AssistantProjectCameraReader::INTENT_PATTERN,
        self::STAFF_TOPIC, '/тендер/iu',
        '/заявк[а-яё]*.*(?:просроч|ответственн|исполнител)/iu'];

    public const TOOLS = [
        'get_project_production_schedule_summary' => AssistantProjectProductionScheduleReader::class,
        'get_project_report_delivery_summary' => AssistantReportDeliverySummaryReader::class,
        'get_project_camera_roster' => AssistantProjectCameraReader::class,
        'get_project_journal_readiness' => AssistantProjectJournalReader::class,
        'get_project_safety_summary' => AssistantProjectSafetyReader::class,
        'get_project_change_readiness' => AssistantProjectChangeReader::class,
        'get_project_design_readiness' => AssistantProjectDesignReader::class,
        'get_project_proposal_readiness' => AssistantProjectProposalReader::class,
        'get_project_executive_readiness' => AssistantProjectExecutiveReader::class,
        'get_project_production_readiness' => AssistantProjectProductionReader::class,
        'get_project_crm_readiness' => AssistantProjectCrmReader::class,
        'get_project_export_history' => AssistantProjectExportHistoryReader::class,
        'get_project_report_catalog' => AssistantProjectReportCatalogReader::class,
        'get_project_site_request_queue' => AssistantProjectSiteRequestReader::class,
        'get_project_material_request_queue' => AssistantProjectMaterialRequestReader::class,
        'get_project_tender_decisions' => AssistantTenderReader::class,
        'get_project_deliveries' => AssistantProjectDeliveriesReader::class,
        'get_project_daily_staff' => AssistantProjectDailyStaffReader::class,
        'get_project_staff_roster' => AssistantProjectStaffReader::class,
        'get_project_act_readiness' => AssistantProjectActReader::class,
    ];

    public const ORGANIZATION_TOOLS = [
        'get_organization_advance_settings' => AssistantAdvanceSettingsReader::class,
        'get_organization_contacts' => AssistantOrganizationContactsReader::class,
        'get_own_notification_preferences' => AssistantNotificationPreferencesReader::class,
        'get_organization_access_review_summary' => AssistantAccessRecertificationReader::class,
        'get_organization_one_c_exchange_summary' => AssistantOneCExchangeSummaryReader::class,
        'get_organization_user_counts' => AssistantOrganizationUsersReader::class,
        'get_assignable_user_roles' => AssistantAssignableRolesReader::class,
        'get_organization_business_profile' => AssistantOrganizationProfileReader::class,
        'get_own_report_workspace' => AssistantReportWorkspaceReader::class,
        'get_own_organization_roles' => AssistantOwnRolesReader::class,
        'get_organization_verification' => AssistantOrganizationVerificationReader::class,
        'get_own_report_subscriptions' => AssistantReportSubscriptionsReader::class,
        'get_report_subscription_catalog' => AssistantReportSubscriptionCatalogReader::class,
        'get_own_admin_permissions' => AssistantOwnAdminPermissionsReader::class,
        'get_organization_credit_balance' => AssistantOrganizationCreditBalanceReader::class,
        'get_organization_active_packages' => AssistantOrganizationPackagesReader::class,
        'get_organization_effective_modules' => AssistantOrganizationModulesReader::class,
        'get_organization_project_roster' => AssistantOrganizationProjectsReader::class,
        'get_organization_supplier_roster' => AssistantOrganizationSuppliersReader::class,
        'get_organization_activity_summary' => AssistantOrganizationActivityReader::class,
        'get_own_project_access' => AssistantOwnProjectAccessReader::class,
        'get_organization_purchase_orders' => AssistantOrganizationPurchaseOrdersReader::class,
        'get_organization_notifications' => AssistantOrganizationNotificationsReader::class,
    ];

    public const NAMED_TOOLS = ['get_catalog_lookup' => AssistantCatalogLookupReader::class, 'get_contract_supplementary_readiness' => AssistantContractSupplementaryReader::class];

    public static function forReference(string $type): ?string
    {
        foreach ([...self::TOOLS, ...self::ORGANIZATION_TOOLS, ...self::NAMED_TOOLS] as $class) {
            if ($class::TYPE === $type) {
                return $class;
            }
        }

        return null;
    }

    public static function intent(string $query): ?array
    {
        $query = \App\BusinessModules\Features\AIAssistant\Services\AssistantNamedProjectReadScope::semanticQuery($query);
        if (AssistantProjectProductionScheduleReader::hasIntent($query)) {
            if (! AssistantProjectProductionScheduleReader::matches($query) || preg_match('/каталог|справочник|шаблон/iu', $query) === 1
                || self::hasProjectNameContinuation($query)) {
                return null;
            }
            $withoutScheduleWording = preg_replace('/\b(?:график[а-яё]*|планов[а-яё]*\s+срок[а-яё]*)\b/iu', ' ', $query) ?? $query;
            foreach (AssistantRequiredProjectRead::READERS as $reader) {
                if (method_exists($reader, 'hasIntent') ? $reader::hasIntent($query) : ($reader::matches($query) || $reader::matches($withoutScheduleWording))) {
                    return null;
                }
            }

            return ['name' => 'get_project_production_schedule_summary', 'reader' => AssistantProjectProductionScheduleReader::class];
        }
        if (AssistantProjectMaterialRequestReader::hasIntent($query) && ! AssistantProjectMaterialRequestReader::matches($query)) {
            return null;
        }
        if (preg_match('/что\s+написано|выдержк|цитат|содержани|текст[а-яё]*\s+(?:документ|предложен)|объясни[а-яё]*\s+правил/iu', $query) === 1) {
            return null;
        }
        if (AssistantReportDeliverySummaryReader::matches($query)) {
            foreach (AssistantRequiredProjectRead::READERS as $reader) {
                if (method_exists($reader, 'hasIntent') ? $reader::hasIntent($query) : $reader::matches($query)) {
                    return null;
                }
            }
            foreach (self::TOPICS as $pattern) {
                if (in_array($pattern, [AssistantProjectExportHistoryReader::INTENT_PATTERN, AssistantProjectReportCatalogReader::INTENT_PATTERN], true)) {
                    continue;
                }
                if (preg_match($pattern, $query) === 1) {
                    return null;
                }
            }
            foreach (self::ORGANIZATION_TOOLS as $reader) {
                if ($reader !== AssistantReportSubscriptionsReader::class && $reader::hasIntent($query)) {
                    return null;
                }
            }
            foreach (self::NAMED_TOOLS as $reader) {
                if ($reader::hasIntent($query)) {
                    return null;
                }
            }
            if (preg_match('/каталог|шаблон/iu', $query) === 1) {
                return null;
            }

            return ['name' => 'get_project_report_delivery_summary', 'reader' => AssistantReportDeliverySummaryReader::class];
        }
        foreach (self::NAMED_TOOLS as $name => $class) {
            if (! $class::hasIntent($query)) {
                continue;
            }
            foreach (self::TOPICS as $pattern) {
                if (preg_match($pattern, $query) === 1) {
                    return null;
                }
            }
            foreach (self::ORGANIZATION_TOOLS as $organizationReader) {
                if ($organizationReader::hasIntent($query)) {
                    return null;
                }
            }

            return $class::matches($query) ? ['name' => $name, 'reader' => $class] : null;
        }
        $purchaseOrders = AssistantOrganizationPurchaseOrdersReader::matches($query);
        $ownPermissions = AssistantOwnAdminPermissionsReader::matches($query);
        $subscriptionCatalog = AssistantReportSubscriptionCatalogReader::matches($query);
        $financeQuery = AssistantProjectReportCatalogReader::matches($query)
            ? preg_replace('/для\s+проверки\s+оплат(?:,?\s+работ(?:\s+и\s+остатков)?)?/iu', '', $query) ?? $query
            : $query;
        if ($subscriptionCatalog) {
            $financeQuery = AssistantReportSubscriptionCatalogReader::intentQuery($financeQuery);
        }
        if (AssistantOrganizationModulesReader::matches($query)) {
            $financeQuery = preg_replace('/без\s+плат[её]ж[а-яё]*\s+(?:подробност|детал)[а-яё]*/iu', '', $financeQuery) ?? $financeQuery;
        }
        $financeQuery = AssistantProjectMaterialRequestReader::intentQuery($financeQuery);
        $financeQuery = AssistantProjectActReader::intentQuery($financeQuery);
        if (AssistantAdvanceSettingsReader::matches($query)) {
            $financeQuery = preg_replace('/подотч[её]тн[а-яё]*\s+расход[а-яё]*/iu', '', $financeQuery) ?? $financeQuery;
        }
        $governance = AssistantOwnAdminPermissionsReader::matches($query) || AssistantOrganizationCreditBalanceReader::matches($query) || AssistantOrganizationPackagesReader::matches($query);
        if ((! $governance && preg_match('/расход|потрач|прибыл|выручк|рентабел|марж|бюджет|затрат|сч[её]т|оплач|оплат|плат[её]ж|смет|табел|техник|при[её]мк/iu', $financeQuery) === 1)
            || (! $ownPermissions && ! $purchaseOrders && ! AssistantProjectDeliveriesReader::matches($query) && preg_match('/поставк|закуп|снабжен|склад/iu', $query) === 1)
            || (! $ownPermissions && ! $purchaseOrders && ! AssistantProjectDeliveriesReader::matches($query) && preg_match('/поставщик/iu', $query) === 1 && ! AssistantOrganizationSuppliersReader::matches($query))
            || (! $governance && preg_match('/договор/iu', $query) === 1 && ! AssistantProjectActReader::matches($query))) {
            return null;
        }
        $topics = 0;
        $topicQuery = AssistantAccessRecertificationReader::matches($query)
            ? preg_replace('/((?:провер(?:к|ок)|пересмотр|ревизи|переаттестаци)[а-яё]*\s+доступ[а-яё]*)\s+(?:сотрудник[а-яё]*|персонал[а-яё]*)/iu', '$1', $query) ?? $query
            : $query;
        $topicQuery = AssistantAdvanceSettingsReader::intentQuery($topicQuery);
        foreach (self::TOPICS as $pattern) {
            if ($pattern === AssistantProjectReportCatalogReader::INTENT_PATTERN && $subscriptionCatalog) {
                continue;
            }
            if ($pattern === self::STAFF_TOPIC && AssistantAssignableRolesReader::matches($query)) {
                continue;
            }
            if (preg_match($pattern, $topicQuery) === 1) {
                $topics++;
            }
        }
        $organizationMatches = [];
        foreach (self::ORGANIZATION_TOOLS as $name => $class) {
            if ($class === AssistantReportSubscriptionsReader::class && $subscriptionCatalog) {
                continue;
            }
            if ($class === AssistantOrganizationPackagesReader::class && ($subscriptionCatalog || (AssistantReportSubscriptionsReader::matches($query)
                && preg_match('/пакет|тариф|биллинг|оплат|продлен|стоим|цен[аы]|баланс|единиц/iu', $query) !== 1))) {
                continue;
            }
            if (AssistantAdvanceSettingsReader::matches($query) && in_array($class, [AssistantNotificationPreferencesReader::class, AssistantOrganizationNotificationsReader::class], true)) {
                continue;
            }
            if (AssistantOwnRolesReader::matches($query) && $class === AssistantAssignableRolesReader::class) {
                continue;
            }
            if ($ownPermissions && in_array($class, [AssistantOrganizationNotificationsReader::class, AssistantNotificationPreferencesReader::class], true)) {
                continue;
            }
            if (AssistantNotificationPreferencesReader::matches($query) && in_array($class, [AssistantOrganizationNotificationsReader::class, AssistantOrganizationActivityReader::class], true)) {
                continue;
            }
            if ($purchaseOrders && $class === AssistantOrganizationSuppliersReader::class) {
                continue;
            }
            if ($class::hasIntent($query)) {
                $organizationMatches[] = $class::matches($query) ? ['name' => $name, 'reader' => $class] : null;
            }
        }
        if ($organizationMatches !== []) {
            return $topics === 0 && count($organizationMatches) === 1 ? $organizationMatches[0] : null;
        }
        if ($topics !== 1) {
            return null;
        }
        $matches = [];
        foreach (self::TOOLS as $name => $class) {
            if ($class === AssistantProjectSiteRequestReader::class && AssistantProjectMaterialRequestReader::matches($query)) {
                continue;
            }
            if ($class::matches($query)) {
                $matches[] = ['name' => $name, 'reader' => $class];
            }
        }
        if (preg_match('/стоим/iu', $query) === 1 && ($matches[0]['name'] ?? null) !== 'get_project_change_readiness') {
            return null;
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    public static function hasIntent(string $query): bool
    {
        $query = \App\BusinessModules\Features\AIAssistant\Services\AssistantNamedProjectReadScope::semanticQuery($query);
        if (AssistantProjectProductionScheduleReader::hasIntent($query)) {
            return true;
        }
        foreach (self::ORGANIZATION_TOOLS as $class) {
            if ($class::hasIntent($query)) {
                return true;
            }
        }
        foreach (self::NAMED_TOOLS as $class) {
            if ($class::hasIntent($query)) {
                return true;
            }
        }

        return self::hasTopic($query);
    }

    public static function hasProjectNameContinuation(string $query): bool
    {
        if (self::hasTopic($query) || AssistantProjectProductionScheduleReader::hasExcludedTopic($query)) {
            return true;
        }
        foreach ([...self::ORGANIZATION_TOOLS, ...self::NAMED_TOOLS] as $class) {
            if ($class::hasIntent($query)) {
                return true;
            }
        }

        return self::hasOtherProjectIntent($query);
    }

    private static function hasOtherProjectIntent(string $query): bool
    {
        foreach (self::TOOLS as $reader) {
            if ($reader !== AssistantProjectProductionScheduleReader::class
                && (method_exists($reader, 'hasIntent') ? $reader::hasIntent($query) : $reader::matches($query))) {
                return true;
            }
        }

        return false;
    }

    private static function hasTopic(string $query): bool
    {
        foreach (self::TOPICS as $pattern) {
            if (preg_match($pattern, $query) === 1) {
                return true;
            }
        }

        return false;
    }
}
