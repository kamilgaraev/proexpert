<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class CatalogMaterialsRagSource extends DeclaredMetadataRagSource
{
    public function sourceType(): string
    {
        return 'catalog_materials';
    }
}
