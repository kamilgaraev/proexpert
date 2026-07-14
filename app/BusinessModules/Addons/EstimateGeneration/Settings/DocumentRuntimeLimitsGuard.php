<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use DomainException;
use Illuminate\Database\Connection;

final readonly class DocumentRuntimeLimitsGuard implements DocumentRuntimeLimits
{
    public function __construct(private Connection $database) {}

    public function assertWithinTotalPages(AiOperationContext $context, EffectiveEstimateGenerationSettings $settings): void
    {
        $pages = $this->database->table('estimate_generation_document_pages')
            ->where('organization_id', $context->organizationId)
            ->where('session_id', $context->sessionId)
            ->count();
        if ($pages > $settings->maxTotalPages()) {
            throw new DomainException('estimate_generation_document_total_page_limit_exceeded');
        }
    }
}
