<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class VideoMonitoringRagSource extends SafeMetadataRagSource
{
    public function sourceType(): string { return 'video_monitoring'; }
}
