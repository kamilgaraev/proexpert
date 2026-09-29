<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforceExportPackageFileRecord extends WorkforceReadModel
{
    protected $table = 'workforce_export_package_files';
    protected $casts = ['organization_id' => 'integer'];
}
