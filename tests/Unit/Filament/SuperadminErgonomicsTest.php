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
            'knowledge_articles' => 'app/Filament/Resources/KnowledgeArticleResource.php',
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
            strpos($source, 'ActionGroup::make(['),
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

        $this->assertStringContainsString("Section::make(trans_message('blog_cms.form_section_editor_notes'))", $source);
        $this->assertStringContainsString('->collapsed()', $source);
    }

    public function test_blog_create_form_does_not_render_broken_workspace_view(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogArticleForm.php'));

        $this->assertStringNotContainsString('editor_workspace_overview', $source);
        $this->assertStringNotContainsString('workspace-overview', $source);
        $this->assertFileDoesNotExist(resource_path('views/filament/blog/article-editor/workspace-overview.blade.php'));

        $titlePosition = strpos($source, "Section::make(trans_message('blog_cms.form_section_title_address'))");
        $publicationPosition = strpos($source, "Section::make(trans_message('blog_cms.form_section_publication'))");
        $bodyPosition = strpos($source, "Section::make(trans_message('blog_cms.form_section_content'))");

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
            "/BlogInlineBlockEditor::make\\('editor_document'\\)[\\s\\S]+?->hiddenOn\\(Operation::Create\\)/",
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

        $this->assertStringContainsString('->viteTheme([', $providerSource);
        $this->assertStringContainsString("'resources/css/filament/admin/theme.css'", $providerSource);
        $this->assertStringContainsString("'resources/js/filament/blog-inline-block-editor.js'", $providerSource);
        $this->assertStringContainsString("'resources/css/filament/admin/theme.css'", $viteSource);
        $this->assertStringContainsString("'resources/js/filament/blog-inline-block-editor.js'", $viteSource);
        $this->assertStringContainsString("darkMode: 'class'", $tailwindSource);
        $this->assertStringContainsString("'./app/Filament/**/*.php'", $tailwindSource);
        $this->assertFileExists($themePath);

        $themeSource = (string) file_get_contents($themePath);

        $this->assertStringContainsString('vendor/filament/filament/dist/theme.css', $themeSource);
        $this->assertStringContainsString('@tailwind base;', $themeSource);
        $this->assertStringContainsString('@tailwind utilities;', $themeSource);
    }

    public function test_filament_theme_keeps_light_and_dark_form_surfaces_separate(): void
    {
        $themeSource = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

        foreach ([
            'background-color: rgb(255 255 255 / 0.96)',
            'color: rgb(15 23 42)',
            'border-color: rgb(217 119 6 / 0.62)',
            '.fi-sc-actions.fi-sticky > .fi-ac',
            '.dark .fi-input-wrp',
            '.dark .fi-btn.fi-outlined',
            '.dark .fi-sc-actions.fi-sticky > .fi-ac',
        ] as $sourceFragment) {
            $this->assertStringContainsString($sourceFragment, $themeSource);
        }
    }

    public function test_filament_vite_theme_manifest_and_assets_are_available_for_production(): void
    {
        $providerSource = (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        $manifestPath = public_path('build/manifest.json');

        $this->assertStringContainsString("'resources/css/filament/admin/theme.css'", $providerSource);
        $this->assertStringContainsString("'resources/js/filament/blog-inline-block-editor.js'", $providerSource);
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('resources/css/filament/admin/theme.css', $manifest);

        $trackedFiles = $this->trackedPublicBuildFiles();

        $this->assertContains('public/build/manifest.json', $trackedFiles);

        foreach ($manifest as $entry) {
            $this->assertIsArray($entry);
            $this->assertIsString($entry['file'] ?? null);

            $relativePath = 'public/build/'.$entry['file'];

            $this->assertFileExists(base_path($relativePath));
            $this->assertContains($relativePath, $trackedFiles);
        }
    }

    public function test_filament_theme_preserves_core_icon_dimensions_after_tailwind_purge(): void
    {
        $themeSource = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));
        $assetSource = (string) file_get_contents($this->filamentThemeBuildAssetPath());

        foreach ([
            '.fi-icon {',
            '.fi-icon.fi-size-xs',
            '.fi-icon.fi-size-sm',
            '.fi-icon.fi-size-md',
            '.fi-icon.fi-size-lg',
            '.fi-icon.fi-size-xl',
            '.fi-icon.fi-size-2xl',
            '.fi-icon > svg',
        ] as $sourceFragment) {
            $this->assertStringContainsString($sourceFragment, $themeSource);
        }

        foreach ([
            '.fi-icon{width:1.25rem;height:1.25rem;',
            '.fi-icon.fi-size-xs{width:.75rem;height:.75rem;',
            '.fi-icon.fi-size-sm{width:1rem;height:1rem;',
            '.fi-icon.fi-size-md{width:1.25rem;height:1.25rem;',
            '.fi-icon.fi-size-lg{width:1.5rem;height:1.5rem;',
            '.fi-icon.fi-size-xl{width:1.75rem;height:1.75rem;',
            '.fi-icon.fi-size-2xl{width:2rem;height:2rem;',
            '.fi-icon>svg{width:inherit;height:inherit;',
        ] as $assetFragment) {
            $this->assertStringContainsString($assetFragment, $assetSource);
        }
    }

    public function test_blog_editor_custom_views_keep_business_friendly_russian_copy(): void
    {
        $outlineSource = (string) file_get_contents(resource_path('views/filament/blog/article-editor/outline.blade.php'));

        $this->assertStringNotContainsString('Outline', $outlineSource);
        $this->assertStringContainsString("trans_message('blog_cms.editor_outline_title')", $outlineSource);
        $this->assertStringContainsString("trans_message('blog_cms.editor_outline_empty')", $outlineSource);
    }

    public function test_blog_editor_primary_ui_copy_is_translation_backed(): void
    {
        $source = implode("\n", [
            (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogArticleForm.php')),
            (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogEditorBlockCatalog.php')),
            (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogEditorBlocks.php')),
            (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Pages/CreateBlogArticle.php')),
            (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Pages/EditBlogArticle.php')),
            (string) file_get_contents(resource_path('views/filament/forms/components/blog-inline-block-editor.blade.php')),
        ]);

        foreach ([
            'form_section_title_address',
            'form_section_title_address_description',
            'field_title',
            'field_slug',
            'form_section_publication',
            'form_section_publication_description',
            'field_status',
            'field_sort_order',
            'field_is_featured',
            'field_allow_comments',
            'field_rss_visibility',
            'form_section_content',
            'form_section_content_description',
            'field_excerpt',
            'placeholder_excerpt',
            'field_editor_document',
            'inline_editor_add_block',
            'form_section_author_category',
            'form_section_author_category_description',
            'field_author',
            'field_category',
            'field_tags',
            'form_section_media',
            'form_section_media_description',
            'field_featured_image',
            'field_gallery',
            'form_section_editor_notes',
            'form_section_editor_notes_description',
            'form_section_seo',
            'form_section_seo_description',
            'create_subheading',
            'edit_subheading',
            'action_preview',
            'action_autosave',
            'action_publish',
            'action_schedule',
            'action_to_draft',
            'action_archive',
            'action_duplicate',
            'editor_block_paragraph',
            'editor_block_heading',
            'editor_block_list',
            'editor_block_quote',
            'editor_block_image',
            'editor_block_gallery',
            'editor_block_table',
            'editor_block_code',
            'editor_block_divider',
            'editor_field_text',
            'editor_field_caption',
            'editor_field_link',
            'editor_field_description',
        ] as $translationKey) {
            $this->assertStringContainsString("trans_message('blog_cms.{$translationKey}')", $source);
        }

        $this->assertStringNotContainsString('protected ?string $subheading = \'', $source);
    }

    /**
     * @return list<string>
     */
    private function trackedPublicBuildFiles(): array
    {
        exec('git ls-files -- public/build', $output, $exitCode);

        $this->assertSame(0, $exitCode);

        return array_values(array_filter(
            $output,
            static fn (string $path): bool => $path !== '',
        ));
    }

    private function filamentThemeBuildAssetPath(): string
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($manifest);
        $this->assertIsString($manifest['resources/css/filament/admin/theme.css']['file'] ?? null);

        return public_path('build/'.$manifest['resources/css/filament/admin/theme.css']['file']);
    }
}
