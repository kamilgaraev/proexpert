<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Models\TimekeepingPeriod;
use App\Http\Resources\File\FileResource;
use App\Models\File;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class PrivateFileDisclosureTest extends TestCase
{
    public function test_generic_file_presenter_does_not_expose_hr_names_paths_urls_or_metadata(): void
    {
        $file = new File(['fileable_type' => WorkforceEmployee::class, 'fileable_id' => 7, 'category' => 'workforce_hr',
            'name' => 'Кадровый документ.pdf', 'path' => 'org-1/workforce/hr/7/private', 'disk' => 's3',
            'additional_info' => ['visibility' => 'private', 'hr_employee_id' => 7, 'thumbnails' => ['small' => ['url' => 'https://example.org/private']]]]);
        $file->id = 4;
        self::assertSame('', $file->url);
        self::assertNull($file->getThumbnailUrl('small'));
        self::assertSame(['id' => 4, 'private' => true], (new FileResource($file))->toArray(Request::create('/files')));
    }

    public function test_timekeeping_morph_is_protected_even_without_a_category(): void
    {
        $file = new File(['fileable_type' => TimekeepingPeriod::class, 'fileable_id' => 9, 'category' => null,
            'path' => 'org-1/workforce/timekeeping/9/private', 'disk' => 's3']);
        self::assertTrue($file->isWorkforcePrivateDocument());
        self::assertSame('', $file->url);
    }
}
