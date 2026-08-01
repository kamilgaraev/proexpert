<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\Concerns;

use LogicException;

trait RejectsProcurementSourceMutation
{
    public function save(array $options = [])
    {
        if ($this->exists) {
            throw $this->immutableSource();
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw $this->immutableSource();
    }

    public function delete()
    {
        throw $this->immutableSource();
    }

    private function immutableSource(): LogicException
    {
        return new LogicException('procurement_reporting_source_is_append_only');
    }
}
