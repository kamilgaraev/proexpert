<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceRecognitionGrain;
use Carbon\CarbonImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionAcceptanceRecognitionGrainTest extends TestCase
{
    #[Test]
    public function reversal_on_another_day_never_rewrites_acceptance_day_grain(): void
    {
        $accepted = $this->event('2026-07-28T11:00:00+03:00');
        $reversed = $this->event('2026-07-30T09:15:00+03:00');
        $grain = new ProductionAcceptanceRecognitionGrain;
        $timezone = new DateTimeZone('Europe/Moscow');

        self::assertSame(
            '7:2026-07-28:volume:m3:RUB:51:performance_act_line:91:77',
            $grain->key($accepted, $timezone),
        );
        self::assertSame(
            '7:2026-07-30:volume:m3:RUB:51:performance_act_line:91:77',
            $grain->key($reversed, $timezone),
        );
        self::assertNotSame(
            $grain->key($accepted, $timezone),
            $grain->key($reversed, $timezone),
        );
    }

    private function event(string $recognizedAt): ProductionAcceptanceEvent
    {
        $event = new ProductionAcceptanceEvent;
        $event->setRawAttributes([
            'performance_act_id' => 51,
            'project_id' => 7,
            'recognized_at' => CarbonImmutable::parse($recognizedAt),
            'source_line_id' => 91,
            'source_line_type' => 'performance_act_line',
            'unit_code' => 'm3',
            'unit_dimension' => 'volume',
            'work_id' => 77,
            'currency' => 'RUB',
        ]);

        return $event;
    }
}
