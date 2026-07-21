<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\ProjectCommandCenter;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectCommandCenterData;
use App\Models\Project;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProjectCommandCenterDataTest extends TestCase
{
    public function test_it_provides_the_stable_empty_command_center_contract(): void
    {
        $project = new Project([
            'name' => 'Строительная площадка',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $project->setAttribute('id', 42);

        $data = ProjectCommandCenterData::empty(
            project: $project,
            period: 'project',
            dateFrom: null,
            dateTo: null,
            generatedAt: new DateTimeImmutable('2026-07-21T12:00:00+03:00'),
        );

        self::assertSame([
            'project',
            'period',
            'generated_at',
            'problems',
            'finance',
            'delivery',
            'analytics',
        ], array_keys($data->toArray()));
        self::assertSame(42, $data->toArray()['project']['id']);
        self::assertSame('project', $data->toArray()['period']['preset']);
        self::assertSame([], $data->toArray()['problems']);
        self::assertSame([], $data->toArray()['finance']);
        self::assertSame([], $data->toArray()['delivery']);
        self::assertSame([], $data->toArray()['analytics']);
    }
}
