<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantLegalBusinessMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\LegalBusinessRagSource;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeClaimHistoryCheckpoint;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LegalBusinessRagTimestampTest extends TestCase
{
    public function test_all_four_collectors_normalize_actual_native_model_version_columns(): void
    {
        $method = new ReflectionMethod(LegalBusinessRagSource::class,'chunk');
        $sources = [];
        foreach (Metadata::sourceClasses() as $class) { $sources[(new $class)->sourceType()] = new $class; }
        self::assertCount(4,$sources);
        foreach (Metadata::entityDefinitions() as $type => [$source,$class]) {
            $model = new $class;
            $model->setDateFormat('Y-m-d H:i:s.uP');
            $attributes = ['id'=>77,'organization_id'=>17];
            foreach (Metadata::versionColumns()[$type] as $column) { $attributes[$column] = '2026-09-29 10:11:12.123456+03:00'; }
            $model->setRawAttributes($attributes,true);
            $chunk = $method->invoke($sources[$source],$model,$type,17);
            self::assertSame($source,$chunk->sourceType,$type);
            if (Metadata::versionColumns()[$type] === []) {
                self::assertNull($chunk->updatedAt,$type);
            } else {
                self::assertInstanceOf(DateTimeInterface::class,$chunk->updatedAt,$type);
                self::assertSame('2026-09-29T10:11:12+03:00',$chunk->updatedAt->format('c'),$type);
                self::assertSame('123456',$chunk->updatedAt->format('u'),$type);
            }
        }
    }

    public function test_uncast_immutable_checkpoint_timestamp_is_preserved_and_null_remains_null(): void
    {
        $model = new ChangeClaimHistoryCheckpoint;
        $model->setRawAttributes(['id'=>77,'organization_id'=>17,'updated_at'=>'2026-09-29 10:11:12.123456+03:00'],true);
        self::assertFalse($model->usesTimestamps());
        self::assertIsString($model->getAttribute('updated_at'));
        $method = new ReflectionMethod(LegalBusinessRagSource::class,'chunk');
        $source = new \App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ChangeBusinessRagSource;
        $chunk = $method->invoke($source,$model,'change_history_checkpoint',17);
        self::assertSame('2026-09-29T10:11:12.123456+03:00',$chunk->updatedAt?->format('Y-m-d\TH:i:s.uP'));
        $model->setRawAttributes(['id'=>77,'organization_id'=>17],true);
        self::assertNull($method->invoke($source,$model,'change_history_checkpoint',17)->updatedAt);
    }
}
