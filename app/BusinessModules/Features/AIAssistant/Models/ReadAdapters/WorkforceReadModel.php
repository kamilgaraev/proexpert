<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class WorkforceReadModel extends Model
{
    protected $guarded = ['*'];

    public function save(array $options = []): bool
    {
        throw new LogicException('Assistant workforce adapters are read only.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Assistant workforce adapters are read only.');
    }
}
