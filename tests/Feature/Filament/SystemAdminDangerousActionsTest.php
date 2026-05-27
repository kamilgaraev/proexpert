<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Tests\TestCase;

class SystemAdminDangerousActionsTest extends TestCase
{
    public function test_critical_saas_resources_do_not_register_bulk_delete_actions(): void
    {
        foreach ([
            'app/Filament/Resources/OrganizationResource.php',
            'app/Filament/Resources/SubscriptionPlanResource.php',
        ] as $relativePath) {
            $source = $this->source($relativePath);

            $this->assertStringNotContainsString('DeleteBulkAction', $source, "{$relativePath} must not expose bulk delete");
            $this->assertStringNotContainsString('BulkActionGroup::make', $source, "{$relativePath} must not expose grouped destructive bulk actions");
        }
    }

    public function test_remaining_delete_actions_use_destructive_guardrails(): void
    {
        foreach ([
            'app/Filament/Resources/BlogArticleResource.php',
            'app/Filament/Resources/BlogCategoryResource.php',
            'app/Filament/Resources/BlogCommentResource.php',
            'app/Filament/Resources/BlogMediaAssetResource.php',
            'app/Filament/Resources/BlogTagResource.php',
            'app/Filament/Resources/NotificationTemplateResource.php',
        ] as $relativePath) {
            $source = $this->source($relativePath);

            $this->assertStringContainsString('guardedDeleteAction', $source, "{$relativePath} must use destructive guardrails");
            $this->assertStringNotContainsString('DeleteAction::make()', $source, "{$relativePath} must not use raw delete action");
        }
    }

    public function test_destructive_guardrails_use_translated_confirmation_copy(): void
    {
        $source = $this->source('app/Filament/Support/Concerns/HasDestructiveActionGuardrails.php');

        $this->assertStringContainsString('requiresConfirmation()', $source);
        $this->assertStringContainsString('trans_message', $source);
        $this->assertStringContainsString('modalHeading', $source);
        $this->assertStringContainsString('modalDescription', $source);
        $this->assertStringContainsString('modalSubmitActionLabel', $source);
    }

    private function source(string $relativePath): string
    {
        return (string) file_get_contents(base_path($relativePath));
    }
}

