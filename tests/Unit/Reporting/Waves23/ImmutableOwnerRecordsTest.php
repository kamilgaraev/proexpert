<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardSnapshot;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverEvidenceEvent;
use App\Services\Customer\Reporting\Sla\Models\CustomerWorkflowEvent;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImmutableOwnerRecordsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Model::setEventDispatcher(new Dispatcher(new Container()));
    }

    protected function tearDown(): void
    {
        Model::unsetEventDispatcher();
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('immutableModels')]
    public function persisted_reporting_records_reject_mutation(string $modelClass): void
    {
        $model = new $modelClass();
        $model->setRawAttributes(['id' => 1, 'source_hash' => str_repeat('a', 64)], true);
        $model->exists = true;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('reporting_record_is_immutable');

        $model->delete();
    }

    public static function immutableModels(): iterable
    {
        yield 'contractor snapshot' => [ContractorScorecardSnapshot::class];
        yield 'handover evidence event' => [HandoverEvidenceEvent::class];
        yield 'customer workflow event' => [CustomerWorkflowEvent::class];
    }
}
