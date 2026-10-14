<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Models\TimekeepingPeriod;
use App\Models\File;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceArchiveProtectionTest extends TestCase
{
    public function test_generic_storage_delete_cannot_remove_archive_bytes(): void
    {
        $context = AdminApiTestContext::create();
        $file = $this->archiveFile($context);
        Storage::shouldReceive('disk')->never();
        try {
            app(FileService::class)->delete($file->path, $context->organization);
            self::fail('Generic storage deletion must not touch the archive');
        } catch (DomainException $exception) {
            self::assertSame(trans_message('workforce.errors.hr_document_disposal_forbidden'), $exception->getMessage());
        }
        self::assertNotNull(File::query()->find($file->id));
    }

    public function test_soft_delete_and_force_delete_cannot_hide_archive_file(): void
    {
        $context = AdminApiTestContext::create();
        $file = $this->archiveFile($context);
        foreach (['delete', 'forceDelete'] as $operation) {
            try {
                $file->{$operation}();
                self::fail('Archive file must remain readable');
            } catch (DomainException $exception) {
                self::assertSame(trans_message('workforce.errors.hr_document_disposal_forbidden'), $exception->getMessage());
            }
            self::assertNotNull(File::query()->find($file->id));
        }
    }

    private function archiveFile(AdminApiTestContext $context): File
    {
        return File::create(['organization_id' => $context->organization->id, 'user_id' => $context->user->id,
            'fileable_type' => TimekeepingPeriod::class, 'fileable_id' => 123, 'name' => 'archive.json', 'original_name' => 'archive.json',
            'path' => 'org-'.$context->organization->id.'/workforce/timekeeping/123/archive/test.json',
            'mime_type' => 'application/json', 'size' => 10, 'disk' => 's3', 'type' => 'document',
            'category' => 'workforce_timekeeping_archive', 'additional_info' => ['visibility' => 'private']]);
    }
}
