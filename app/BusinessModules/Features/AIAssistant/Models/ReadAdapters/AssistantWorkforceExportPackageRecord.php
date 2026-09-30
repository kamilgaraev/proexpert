<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforceExportPackageRecord extends WorkforceReadModel
{
    protected $table = 'workforce_export_packages';
    protected $casts = ['organization_id' => 'integer'];
}
