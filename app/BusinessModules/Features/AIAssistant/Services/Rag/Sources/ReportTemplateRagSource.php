<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class ReportTemplateRagSource extends SafeMetadataRagSource
{
    public function sourceType(): string { return 'report_templates'; }
}
