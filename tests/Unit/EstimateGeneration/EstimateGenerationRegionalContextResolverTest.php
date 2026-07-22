<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationRegionalContextResolver;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationRegionalContextResolverTest extends TestCase
{
    private Capsule $database;

    private ?ConnectionResolverInterface $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousResolver = Model::getConnectionResolver();
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->setAsGlobal();
        $this->database->bootEloquent();

        $schema = $this->database->schema();
        $schema->create('estimate_regions', static function ($table): void {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('estimate_price_zones', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('estimate_region_id');
            $table->string('name');
        });
        $schema->create('estimate_price_periods', static function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter');
        });
        $schema->create('estimate_regional_price_versions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('region_id');
            $table->unsignedInteger('price_zone_id');
            $table->unsignedInteger('period_id');
            $table->string('version_key');
            $table->string('status');
        });
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver instanceof ConnectionResolverInterface) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }

        parent::tearDown();
    }

    #[Test]
    public function it_uses_the_only_active_regional_price_version_when_context_is_not_provided(): void
    {
        $this->insertVersion(150, '2026-q2-ru-ta-r1');

        $context = (new EstimateGenerationRegionalContextResolver)->resolve([]);

        self::assertSame(150, $context['estimate_regional_price_version_id']);
        self::assertSame('2026-q2-ru-ta-r1', $context['version_key']);
        self::assertSame('single_active', $context['source']);
        self::assertSame('active', $context['status']);
    }

    #[Test]
    public function it_keeps_regional_context_unresolved_when_more_than_one_active_version_is_available(): void
    {
        $this->insertVersion(150, '2026-q2-ru-ta-r1');
        $this->insertVersion(151, '2026-q2-ru-mo-r1', 2, 2, 2);

        $context = (new EstimateGenerationRegionalContextResolver)->resolve([]);

        self::assertNull($context['estimate_regional_price_version_id']);
        self::assertSame('regional_context_missing', $context['status']);
    }

    private function insertVersion(int $versionId, string $versionKey, int $regionId = 1, int $priceZoneId = 1, int $periodId = 1): void
    {
        $this->database->table('estimate_regions')->insert([
            'id' => $regionId,
            'name' => 'Region '.$regionId,
        ]);
        $this->database->table('estimate_price_zones')->insert([
            'id' => $priceZoneId,
            'estimate_region_id' => $regionId,
            'name' => 'Zone '.$priceZoneId,
        ]);
        $this->database->table('estimate_price_periods')->insert([
            'id' => $periodId,
            'name' => 'Q2 2026',
            'year' => 2026,
            'quarter' => 2,
        ]);
        $this->database->table('estimate_regional_price_versions')->insert([
            'id' => $versionId,
            'region_id' => $regionId,
            'price_zone_id' => $priceZoneId,
            'period_id' => $periodId,
            'version_key' => $versionKey,
            'status' => 'active',
        ]);
    }
}
