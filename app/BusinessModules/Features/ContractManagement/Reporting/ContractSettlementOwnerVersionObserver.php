<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use Illuminate\Database\Eloquent\Model;

final readonly class ContractSettlementOwnerVersionObserver
{
    public function __construct(private ContractSettlementOwnerVersionRecorder $recorder) {}

    public function created(Model $owner): void
    {
        $this->recorder->record($owner, 'upsert');
    }

    public function updated(Model $owner): void
    {
        $this->recorder->record($owner, 'upsert');
    }

    public function deleted(Model $owner): void
    {
        $this->recorder->record($owner, 'delete');
    }

    public function restored(Model $owner): void
    {
        $this->recorder->record($owner, 'upsert');
    }

    public function forceDeleted(Model $owner): void
    {
        $this->recorder->record($owner, 'delete');
    }
}
