<?php

declare(strict_types=1);

namespace App\Filament\Support;

use ReflectionClass;

final class FilamentPermission
{
    public const ACCESS = 'system_admin.access';

    public const DASHBOARD_VIEW = 'system_admin.dashboard.view';

    public const DASHBOARD_USERS_VIEW = 'system_admin.dashboard.users.view';

    public const DASHBOARD_REVENUE_VIEW = 'system_admin.dashboard.revenue.view';

    public const DASHBOARD_PLANS_VIEW = 'system_admin.dashboard.plans.view';

    public const PROFILE_VIEW = 'system_admin.profile.view';

    public const PROFILE_UPDATE = 'system_admin.profile.update';

    public const API_DOCS_VIEW = 'system_admin.api_docs.view';

    public const ADMINS_VIEW = 'system_admin.admins.view';

    public const ADMINS_CREATE = 'system_admin.admins.create';

    public const ADMINS_UPDATE = 'system_admin.admins.update';

    public const ADMINS_DELETE = 'system_admin.admins.delete';

    public const ADMINS_MANAGE_ROLES = 'system_admin.admins.manage_roles';

    public const USERS_VIEW = 'system_admin.users.view';

    public const USERS_CREATE = 'system_admin.users.create';

    public const USERS_UPDATE = 'system_admin.users.update';

    public const USERS_DELETE = 'system_admin.users.delete';

    public const USERS_BLOCK = 'system_admin.users.block';

    public const USERS_VERIFY_EMAIL = 'system_admin.users.verify_email';

    public const USERS_SEND_PASSWORD_RESET = 'system_admin.users.send_password_reset';

    public const ORGANIZATIONS_VIEW = 'system_admin.organizations.view';

    public const ORGANIZATIONS_CREATE = 'system_admin.organizations.create';

    public const ORGANIZATIONS_UPDATE = 'system_admin.organizations.update';

    public const ORGANIZATIONS_DELETE = 'system_admin.organizations.delete';

    public const ORGANIZATIONS_SUSPEND = 'system_admin.organizations.suspend';

    public const ORGANIZATIONS_REACTIVATE = 'system_admin.organizations.reactivate';

    public const SUBSCRIPTION_PLANS_VIEW = 'system_admin.subscription_plans.view';

    public const SUBSCRIPTION_PLANS_CREATE = 'system_admin.subscription_plans.create';

    public const SUBSCRIPTION_PLANS_UPDATE = 'system_admin.subscription_plans.update';

    public const SUBSCRIPTION_PLANS_DELETE = 'system_admin.subscription_plans.delete';

    public const BILLING_VIEW = 'system_admin.billing.view';

    public const BILLING_REVENUE_VIEW = 'system_admin.billing.revenue.view';

    public const AI_USAGE_VIEW = 'system_admin.ai_usage.view';

    public const PAYMENTS_VIEW = 'system_admin.payments.view';

    public const PAYMENTS_MANAGE = 'system_admin.payments.manage';

    public const SUBSCRIPTIONS_VIEW = 'system_admin.subscriptions.view';

    public const SUBSCRIPTIONS_MANAGE = 'system_admin.subscriptions.manage';

    public const MODULES_VIEW = 'system_admin.modules.view';

    public const MODULES_MANAGE = 'system_admin.modules.manage';

    public const BLOG_ARTICLES_VIEW = 'system_admin.blog.articles.view';

    public const BLOG_ARTICLES_CREATE = 'system_admin.blog.articles.create';

    public const BLOG_ARTICLES_UPDATE = 'system_admin.blog.articles.update';

    public const BLOG_ARTICLES_DELETE = 'system_admin.blog.articles.delete';

    public const BLOG_ARTICLES_PUBLISH = 'system_admin.blog.articles.publish';

    public const BLOG_CATEGORIES_MANAGE = 'system_admin.blog.categories.manage';

    public const BLOG_TAGS_MANAGE = 'system_admin.blog.tags.manage';

    public const BLOG_MEDIA_VIEW = 'system_admin.blog.media.view';

    public const BLOG_MEDIA_UPLOAD = 'system_admin.blog.media.upload';

    public const BLOG_MEDIA_MANAGE = 'system_admin.blog.media.manage';

    public const BLOG_MEDIA_DELETE = 'system_admin.blog.media.delete';

    public const BLOG_COMMENTS_MODERATE = 'system_admin.blog.comments.moderate';

    public const BLOG_SEO_MANAGE = 'system_admin.blog.seo.manage';

    public const BLOG_REVISIONS_VIEW = 'system_admin.blog.revisions.view';

    public const BLOG_REVISIONS_RESTORE = 'system_admin.blog.revisions.restore';

    public const BLOG_PREVIEW_VIEW = 'system_admin.blog.preview.view';

    public const KNOWLEDGE_HUB_ARTICLES_VIEW = 'system_admin.knowledge_hub.articles.view';

    public const KNOWLEDGE_HUB_ARTICLES_CREATE = 'system_admin.knowledge_hub.articles.create';

    public const KNOWLEDGE_HUB_ARTICLES_UPDATE = 'system_admin.knowledge_hub.articles.update';

    public const KNOWLEDGE_HUB_ARTICLES_DELETE = 'system_admin.knowledge_hub.articles.delete';

    public const KNOWLEDGE_HUB_CATEGORIES_MANAGE = 'system_admin.knowledge_hub.categories.manage';

    public const ESTIMATE_GENERATION_MONITOR = 'estimate_generation.monitor';

    public const ESTIMATE_GENERATION_OPERATE = 'estimate_generation.operate';

    public const ESTIMATE_GENERATION_DATASETS = 'estimate_generation.datasets';

    public const ESTIMATE_GENERATION_BENCHMARKS = 'estimate_generation.benchmarks';

    public const ESTIMATE_GENERATION_SETTINGS = 'estimate_generation.settings';

    public const ESTIMATE_GENERATION_BUDGETS = 'estimate_generation.budgets';

    public const NOTIFICATIONS_TEMPLATES_VIEW = 'system_admin.notifications.templates.view';

    public const NOTIFICATIONS_TEMPLATES_CREATE = 'system_admin.notifications.templates.create';

    public const NOTIFICATIONS_TEMPLATES_UPDATE = 'system_admin.notifications.templates.update';

    public const NOTIFICATIONS_TEMPLATES_DELETE = 'system_admin.notifications.templates.delete';

    public const NOTIFICATIONS_DELIVERY_LOG_VIEW = 'system_admin.notifications.delivery_log.view';

    public const NOTIFICATIONS_ANALYTICS_VIEW = 'system_admin.notifications.analytics.view';

    public const NOTIFICATIONS_MANAGE = 'system_admin.notifications.manage';

    public const SUPPORT_VIEW = 'system_admin.support.view';

    public const SUPPORT_MANAGE = 'system_admin.support.manage';

    public const MONITORING_VIEW = 'system_admin.monitoring.view';

    public const MONITORING_MANAGE = 'system_admin.monitoring.manage';

    public const AUDIT_LOGS_VIEW = 'system_admin.audit_logs.view';

    public const AUDIT_LOGS_EXPORT = 'system_admin.audit_logs.export';

    public const SETTINGS_VIEW = 'system_admin.settings.view';

    public const SETTINGS_MANAGE = 'system_admin.settings.manage';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values((new ReflectionClass(self::class))->getConstants());
    }
}
