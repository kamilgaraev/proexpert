<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignProjectIssueResource;
use App\BusinessModules\Features\QualityControl\Http\Resources\QualityDefectResource;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectPhoto;
use App\Models\Organization;
use App\Services\Storage\FileService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class QualityDefectPhotoReferenceSecurityTest extends TestCase
{
    #[DataProvider('unsafeReferences')]
    public function test_unsafe_photo_references_never_reach_a_storage_url_method(
        string $resource,
        string $reference,
        int $photoOrganization,
        int $photoDefect,
    ): void {
        $files = $this->mock(FileService::class);
        $files->shouldNotReceive('temporaryUrl');
        $files->shouldNotReceive('url');
        $defect = $this->defect($reference, $photoOrganization, $photoDefect);

        $data = (new $resource($defect))->resolve(Request::create('/'));

        if ($resource === DesignProjectIssueResource::class) {
            self::assertSame([], $data['photos']);
        } else {
            self::assertNull($data['photos'][0]['preview_url']);
            self::assertNull($data['photos'][0]['path']);
            self::assertEmpty($data['photos'][0]['url']);
            self::assertSame('Сохранённое описание', $data['photos'][0]['caption']);
        }
    }

    public static function unsafeReferences(): array
    {
        $uuid = 'e021fe55-8d05-4c35-83c5-5c0ba24ad7c4.jpg';
        $references = [
            'foreign organization' => ["org-8/quality-control/defects/11/{$uuid}", 7, 11],
            'other defect' => ["org-7/quality-control/defects/12/{$uuid}", 7, 11],
            'other private namespace' => ['org-7/hr/employees/private.pdf', 7, 11],
            'foreign s3 object' => ["s3://org-8/quality-control/defects/11/{$uuid}", 7, 11],
            'same organization s3 object' => ['s3://org-7/hr/private.pdf', 7, 11],
            'wrong photo organization' => ["org-7/quality-control/defects/11/{$uuid}", 8, 11],
            'wrong photo defect' => ["org-7/quality-control/defects/11/{$uuid}", 7, 12],
            'traversal' => ["org-7/quality-control/defects/11/../12/{$uuid}", 7, 11],
            'encoded traversal' => ["org-7/quality-control/defects/11/%2e%2e%2f{$uuid}", 7, 11],
            'backslash' => ["org-7/quality-control/defects/11/..\\{$uuid}", 7, 11],
            'query' => ["org-7/quality-control/defects/11/{$uuid}?key=other", 7, 11],
            'fragment' => ["org-7/quality-control/defects/11/{$uuid}#other", 7, 11],
            'control' => ["org-7/quality-control/defects/11/{$uuid}\n", 7, 11],
            'wrong issue photo' => ['org-7/design-management/issues/12/photo/'.str_repeat('a', 64).'.jpg', 7, 11],
            'snapshot is not a photo' => ['org-7/design-management/issues/11/snapshot/'.str_repeat('a', 64).'.jpg', 7, 11],
        ];
        $cases = [];
        foreach ([QualityDefectResource::class, DesignProjectIssueResource::class] as $resource) {
            foreach ($references as $name => $args) {
                $cases[$resource.' '.$name] = [$resource, ...$args];
            }
        }

        return $cases;
    }

    #[DataProvider('safeStorageReferences')]
    public function test_own_generated_and_legacy_photo_keys_are_signed(string $reference, string $path): void
    {
        $files = $this->mock(FileService::class);
        $files->shouldReceive('temporaryUrl')->once()->with($path, 60, \Mockery::type(Organization::class))
            ->andReturn('https://storage.example/signed-own-photo');
        $files->shouldNotReceive('url');
        $data = (new QualityDefectResource($this->defect($reference)))->resolve(Request::create('/'));

        self::assertSame($path, $data['photos'][0]['path']);
        self::assertSame('https://storage.example/signed-own-photo', $data['photos'][0]['preview_url']);
    }

    public static function safeStorageReferences(): array
    {
        $prefix = 'org-7/quality-control/defects/11/e021fe55-8d05-4c35-83c5-5c0ba24ad7c4.';
        $cases = [];
        foreach (['jpg', 'jpeg', 'JPEG', 'png', 'webp', ''] as $extension) {
            $cases[$extension ?: 'empty legacy extension'] = [$prefix.$extension, $prefix.$extension];
        }
        $cases['s3 legacy'] = ['s3://'.$prefix.'jpeg', $prefix.'jpeg'];

        return $cases;
    }

    public function test_own_bim_photo_is_signed_by_both_resources(): void
    {
        $path = 'org-7/design-management/issues/11/photo/'.str_repeat('b', 64).'.png';
        $files = $this->mock(FileService::class);
        $files->shouldReceive('temporaryUrl')->twice()->with($path, 60, \Mockery::type(Organization::class))
            ->andReturn('https://storage.example/signed-bim-photo');
        $defect = $this->defect($path);

        $quality = (new QualityDefectResource($defect))->resolve(Request::create('/'));
        $design = (new DesignProjectIssueResource($defect))->resolve(Request::create('/'));

        self::assertSame('https://storage.example/signed-bim-photo', $quality['photos'][0]['preview_url']);
        self::assertSame('https://storage.example/signed-bim-photo', $design['photos'][0]['url']);
    }

    public function test_external_https_photo_is_displayed_without_signing(): void
    {
        $files = $this->mock(FileService::class);
        $files->shouldNotReceive('temporaryUrl');
        $files->shouldNotReceive('url');
        $url = 'https://images.example/quality-photo.jpg';
        $data = (new QualityDefectResource($this->defect($url)))->resolve(Request::create('/'));

        self::assertSame($url, $data['photos'][0]['url']);
        self::assertSame($url, $data['photos'][0]['preview_url']);
        self::assertNull($data['photos'][0]['path']);
    }

    private function defect(string $reference, int $photoOrganization = 7, int $photoDefect = 11): QualityDefect
    {
        $organization = (new Organization())->forceFill(['id' => 7]);
        $photo = new QualityDefectPhoto([
            'quality_defect_id' => $photoDefect,
            'organization_id' => $photoOrganization,
            'url' => $reference,
            'type' => 'before',
            'caption' => 'Сохранённое описание',
        ]);
        $defect = (new QualityDefect())->forceFill([
            'id' => 11, 'organization_id' => 7, 'project_id' => 1, 'kind' => 'project',
            'title' => 'Замечание', 'severity' => 'major', 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(), 'inspection_required' => true,
        ]);
        $defect->setRelation('organization', $organization);
        $defect->setRelation('photos', collect([$photo]));

        return $defect;
    }
}
