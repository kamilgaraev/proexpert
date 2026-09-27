<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile;

use App\Services\Mobile\MobileConstructionJournalService;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class MobileConstructionJournalServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app->setLocale('ru');
    }

    public function test_work_volume_without_linked_work_keeps_entry_readable(): void
    {
        $service = (new ReflectionClass(MobileConstructionJournalService::class))->newInstanceWithoutConstructor();
        $transform = new ReflectionMethod(MobileConstructionJournalService::class, 'transformWorkVolumePayload');

        $missingWork = $transform->invoke($service, [
            'id' => 66,
            'estimateItem' => null,
            'workType' => null,
            'measurementUnit' => ['short_name' => 'м³'],
        ]);
        self::assertSame('Работа без наименования', $missingWork['title']);
        self::assertSame('м³', $missingWork['measurement_unit_name']);

        $fallbackWork = $transform->invoke($service, [
            'estimateItem' => ['name' => ''],
            'workType' => ['name' => 'Бетонирование'],
            'measurementUnit' => ['name' => 'кубический метр'],
        ]);
        self::assertSame('Бетонирование', $fallbackWork['title']);
        self::assertSame('кубический метр', $fallbackWork['measurement_unit_name']);
    }
}
