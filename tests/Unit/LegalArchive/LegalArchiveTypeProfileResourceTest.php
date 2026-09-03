<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentTypeProfile;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveTypeProfileResource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class LegalArchiveTypeProfileResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        $container->instance('config', new Repository([
            'legal-document-profiles' => [
                'contract.supply' => ['category' => 'contract', 'required_fields' => ['subject']],
                'other.custom' => ['category' => 'other'],
            ],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_custom_profile_exposes_inherited_category_without_merging_editable_settings(): void
    {
        $profile = new LegalArchiveDocumentTypeProfile([
            'code' => 'organization.supply',
            'base_code' => 'contract.supply',
            'name' => 'Supply with specification',
            'schema' => [],
            'required_fields' => [],
            'required_file_roles' => ['specification'],
            'requires_signature' => null,
        ]);

        foreach ([$profile, $profile->toArray()] as $input) {
            $result = (new LegalArchiveTypeProfileResource($input))->toArray(Request::create('/'));
            self::assertSame('contract', $result['category']);
            self::assertSame([], $result['schema']);
            self::assertSame([], $result['required_fields']);
            self::assertSame(['specification'], $result['required_file_roles']);
            self::assertNull($result['requires_signature']);
        }
    }

    public function test_standard_category_and_unknown_base_are_preserved(): void
    {
        foreach ([
            [['code' => 'standard', 'category' => 'act'], 'act'],
            [['code' => 'custom', 'base_code' => 'other.custom'], 'other'],
            [['code' => 'custom', 'base_code' => 'missing'], null],
            [['code' => 'custom'], null],
        ] as [$input, $category]) {
            $result = (new LegalArchiveTypeProfileResource($input))->toArray(Request::create('/'));
            self::assertSame($category, $result['category']);
        }
    }
}
