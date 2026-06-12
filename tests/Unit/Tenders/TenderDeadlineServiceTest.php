<?php

declare(strict_types=1);

namespace Tests\Unit\Tenders;

use App\BusinessModules\Features\Tenders\Services\TenderDeadlineService;
use PHPUnit\Framework\TestCase;

final class TenderDeadlineServiceTest extends TestCase
{
    public function test_overdue_deadline_ignores_completed_and_terminal_tenders(): void
    {
        $service = new TenderDeadlineService();

        $this->assertTrue($service->isOverdue(now()->subMinute(), null, 'preparation'));
        $this->assertFalse($service->isOverdue(now()->addMinute(), null, 'preparation'));
        $this->assertFalse($service->isOverdue(now()->subMinute(), now(), 'preparation'));
        $this->assertFalse($service->isOverdue(now()->subMinute(), null, 'won'));
        $this->assertFalse($service->isOverdue(now()->subMinute(), null, 'lost'));
        $this->assertFalse($service->isOverdue(now()->subMinute(), null, 'cancelled'));
    }

    public function test_next_deadline_picks_nearest_open_deadline(): void
    {
        $service = new TenderDeadlineService();

        $next = $service->resolveNextDeadline([
            [
                'kind' => 'result',
                'title' => 'Публикация результата',
                'due_at' => now()->addDays(10),
                'completed_at' => null,
            ],
            [
                'kind' => 'submission',
                'title' => 'Подача заявки',
                'due_at' => now()->addDays(2),
                'completed_at' => null,
            ],
            [
                'kind' => 'questions',
                'title' => 'Вопросы',
                'due_at' => now()->addDay(),
                'completed_at' => now(),
            ],
        ]);

        $this->assertSame('submission', $next['kind']);
        $this->assertSame('Подача заявки', $next['title']);
    }
}
