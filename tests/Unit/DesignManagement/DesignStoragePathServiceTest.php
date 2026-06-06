<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Services\DesignStoragePathService;
use Tests\TestCase;

final class DesignStoragePathServiceTest extends TestCase
{
    public function test_source_path_uses_organization_project_package_and_version_scope(): void
    {
        $service = new DesignStoragePathService();

        $path = $service->sourcePath(7, 15, 22, 101, 'model.ifc');

        self::assertSame('org-7/pir/projects/15/packages/22/models/101/source/model.ifc', $path);
    }

    public function test_derivative_path_uses_viewer_folder_and_normalized_extension(): void
    {
        $service = new DesignStoragePathService();

        $path = $service->derivativePath(7, 15, 22, 101, '.FRAG');

        self::assertSame('org-7/pir/projects/15/packages/22/models/101/viewer/model.frag', $path);
    }

    public function test_document_source_path_uses_document_folder_and_safe_name(): void
    {
        $service = new DesignStoragePathService();

        $path = $service->documentSourcePath(7, 15, 22, 101, '..\\АР том 1.pdf');

        self::assertSame('org-7/pir/projects/15/packages/22/documents/101/source/ar-tom-1.pdf', $path);
    }

    public function test_source_path_drops_user_provided_directories(): void
    {
        $service = new DesignStoragePathService();

        $path = $service->sourcePath(7, 15, 22, 101, '..\\unsafe/model.ifc');

        self::assertSame('org-7/pir/projects/15/packages/22/models/101/source/model.ifc', $path);
    }
}
