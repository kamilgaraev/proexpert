<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LatestPackageRevisionQueryTest extends TestCase
{
    #[Test]
    public function limit_is_applied_after_latest_physical_revision_is_selected(): void
    {
        $db = new Capsule;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'contract');
        $db->setAsGlobal();
        $db->bootEloquent();
        $db->getConnection('contract')->getSchemaBuilder()->create('estimate_generation_package_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_id');
            $table->string('key');
            $table->string('logical_key')->nullable();
            $table->unsignedInteger('revision');
            $table->string('item_type');
            $table->string('name');
            $table->unsignedInteger('sort_order');
            $table->timestamps();
        });
        $now = '2026-07-12 00:00:00';
        for ($revision = 1; $revision <= 2; $revision++) {
            for ($index = 1; $index <= 101; $index++) {
                $db->getConnection('contract')->table('estimate_generation_package_items')->insert([
                    'package_id' => 1, 'key' => "work-{$index}#r{$revision}", 'logical_key' => "work-{$index}",
                    'revision' => $revision, 'item_type' => 'priced_work', 'name' => ($revision === 2 ? 'Current ' : 'Old ').$index,
                    'sort_order' => $index * 100, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $model = new EstimateGenerationPackageItem;
        $model->setConnection('contract');
        $items = $model->newQuery()->where('package_id', 1)->latestLogicalRevisions()
            ->orderBy('sort_order')->orderBy('id')->limit(100)->get();

        self::assertCount(100, $items);
        self::assertSame('Current 1', $items->first()->name);
        self::assertSame('Current 100', $items->last()->name);
        self::assertSame(100, $items->where('item_type', 'priced_work')->count());
        self::assertCount(100, $items->pluck('logical_key')->unique());
    }
}
