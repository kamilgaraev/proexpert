<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use Tests\TestCase;

class SuperadminErgonomicsTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    public static function tableEmptyStateFiles(): array
    {
        return [
            'activity_events' => 'app/Filament/Resources/ActivityEventResource.php',
            'application_errors' => 'app/Filament/Resources/Monitoring/ApplicationErrorResource.php',
            'blog_articles' => 'app/Filament/Resources/BlogArticleResource/Schemas/BlogArticleTable.php',
            'blog_categories' => 'app/Filament/Resources/BlogCategoryResource.php',
            'blog_comments' => 'app/Filament/Resources/BlogCommentResource.php',
            'blog_media_assets' => 'app/Filament/Resources/BlogMediaAssetResource.php',
            'blog_revisions' => 'app/Filament/Resources/BlogArticleResource/RelationManagers/BlogArticleRevisionsRelationManager.php',
            'blog_seo_settings' => 'app/Filament/Resources/BlogSeoSettingsResource.php',
            'blog_tags' => 'app/Filament/Resources/BlogTagResource.php',
            'module_activations' => 'app/Filament/Resources/OrganizationModuleActivationResource.php',
            'modules' => 'app/Filament/Resources/ModuleResource.php',
            'notification_analytics' => 'app/Filament/Resources/NotificationAnalyticsResource.php',
            'notification_events' => 'app/Filament/Resources/NotificationResource/RelationManagers/AnalyticsRelationManager.php',
            'notification_templates' => 'app/Filament/Resources/NotificationTemplateResource.php',
            'notifications' => 'app/Filament/Resources/NotificationResource.php',
            'organizations' => 'app/Filament/Resources/OrganizationResource.php',
            'package_subscriptions' => 'app/Filament/Resources/OrganizationPackageSubscriptionResource.php',
            'payments' => 'app/Filament/Resources/PaymentTransactionResource.php',
            'subscription_plans' => 'app/Filament/Resources/SubscriptionPlanResource.php',
            'subscriptions' => 'app/Filament/Resources/OrganizationSubscriptionResource.php',
            'support_requests' => 'app/Filament/Resources/SupportRequestResource.php',
            'system_admins' => 'app/Filament/Resources/SystemAdminResource.php',
            'users' => 'app/Filament/Resources/UserResource.php',
        ];
    }

    public function test_superadmin_tables_have_business_empty_states(): void
    {
        $translations = require base_path('lang/ru/filament_empty_states.php');

        foreach (self::tableEmptyStateFiles() as $key => $relativePath) {
            $source = file_get_contents(base_path($relativePath));

            $this->assertIsString($source);
            $this->assertStringContainsString(
                "TableEmptyState::for(\$table, '{$key}'",
                $source,
                "{$relativePath} must use a business empty state.",
            );
            $this->assertArrayHasKey($key, $translations);
            $this->assertNotEmpty($translations[$key]['heading'] ?? null);
            $this->assertNotEmpty($translations[$key]['description'] ?? null);
        }
    }

    public function test_blog_editor_keeps_draft_actions_separate_from_publication_actions(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Pages/EditBlogArticle.php'));

        $this->assertStringContainsString('ActionGroup::make([', $source);
        $this->assertStringContainsString("->label(trans_message('blog_cms.publication_actions_group'))", $source);
        $this->assertMatchesRegularExpression(
            "/Action::make\\('archive'\\).*?->requiresConfirmation\\(\\)/s",
            $source,
        );
        $this->assertLessThan(
            strpos($source, "ActionGroup::make(["),
            strpos($source, "Action::make('autosave_now')"),
        );
    }

    public function test_blog_article_form_explains_required_editorial_fields(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogArticleForm.php'));

        foreach ([
            'helper_title',
            'helper_slug',
            'helper_excerpt',
            'helper_editor_document',
            'helper_status',
            'helper_category',
            'helper_featured_image',
            'helper_meta_title',
            'helper_meta_description',
        ] as $translationKey) {
            $this->assertStringContainsString("trans_message('blog_cms.{$translationKey}')", $source);
        }

        $this->assertStringContainsString("Section::make('Внутренние заметки')", $source);
        $this->assertStringContainsString('->collapsed()', $source);
    }

    public function test_blog_create_form_does_not_render_broken_workspace_view(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogArticleForm.php'));

        $this->assertStringNotContainsString('editor_workspace_overview', $source);
        $this->assertStringNotContainsString('workspace-overview', $source);
        $this->assertFileDoesNotExist(resource_path('views/filament/blog/article-editor/workspace-overview.blade.php'));

        $titlePosition = strpos($source, "Section::make('Заголовок и адрес')");
        $publicationPosition = strpos($source, "Section::make('Публикация')");
        $bodyPosition = strpos($source, "Section::make('Текст и краткое описание')");

        $this->assertIsInt($titlePosition);
        $this->assertIsInt($publicationPosition);
        $this->assertIsInt($bodyPosition);
        $this->assertLessThan($publicationPosition, $titlePosition);
        $this->assertLessThan($bodyPosition, $publicationPosition);
    }

    public function test_blog_create_form_keeps_heavy_editor_surfaces_for_editing(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogArticleForm.php'));

        $this->assertStringContainsString('use Filament\Support\Enums\Operation;', $source);
        $this->assertMatchesRegularExpression(
            "/ViewField::make\\('editor_outline'\\)[\\s\\S]+?->hiddenOn\\(Operation::Create\\)/",
            $source,
        );
        $this->assertMatchesRegularExpression(
            "/Builder::make\\('editor_document'\\)[\\s\\S]+?->hiddenOn\\(Operation::Create\\)/",
            $source,
        );
        $this->assertMatchesRegularExpression(
            "/Section::make\\(trans_message\\('blog_cms\\.editorial_checklist_section'\\)\\)[\\s\\S]+?->hiddenOn\\(Operation::Create\\)/",
            $source,
        );
        $this->assertMatchesRegularExpression(
            "/ViewField::make\\('seo_preview'\\)[\\s\\S]+?->hiddenOn\\(Operation::Create\\)/",
            $source,
        );
    }

    public function test_filament_panel_loads_project_theme_for_custom_blog_editor_views(): void
    {
        $providerSource = (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        $viteSource = (string) file_get_contents(base_path('vite.config.js'));
        $tailwindSource = (string) file_get_contents(base_path('tailwind.config.js'));
        $themePath = resource_path('css/filament/admin/theme.css');

        $this->assertStringContainsString("->viteTheme('resources/css/filament/admin/theme.css')", $providerSource);
        $this->assertStringContainsString("'resources/css/filament/admin/theme.css'", $viteSource);
        $this->assertStringContainsString("darkMode: 'class'", $tailwindSource);
        $this->assertStringContainsString("'./app/Filament/**/*.php'", $tailwindSource);
        $this->assertFileExists($themePath);

        $themeSource = (string) file_get_contents($themePath);

        $this->assertStringContainsString("vendor/filament/filament/dist/theme.css", $themeSource);
        $this->assertStringContainsString('@tailwind base;', $themeSource);
        $this->assertStringContainsString('@tailwind utilities;', $themeSource);
    }

    public function test_blog_editor_custom_views_keep_business_friendly_russian_copy(): void
    {
        $outlineSource = (string) file_get_contents(resource_path('views/filament/blog/article-editor/outline.blade.php'));

        $this->assertStringNotContainsString('Outline', $outlineSource);
        $this->assertStringContainsString("trans_message('blog_cms.editor_outline_title')", $outlineSource);
        $this->assertStringContainsString("trans_message('blog_cms.editor_outline_empty')", $outlineSource);
    }
}
