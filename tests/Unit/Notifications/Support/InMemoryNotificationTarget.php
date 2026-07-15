<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Support;

use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use Throwable;

final class InMemoryNotificationTarget extends NotificationTarget
{
    protected $dateFormat = 'Y-m-d H:i:s';

    private array $committedAttributes;

    private array $saveFailures = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct();
        $this->forceFill($attributes);
        $this->committedAttributes = $this->getAttributes();
    }

    public function failNextSave(Throwable $exception): void
    {
        $this->saveFailures[] = $exception;
    }

    public function save(array $options = []): bool
    {
        if ($this->saveFailures !== []) {
            throw array_shift($this->saveFailures);
        }

        $this->committedAttributes = $this->getAttributes();

        return true;
    }

    public function fresh($with = []): ?static
    {
        $fresh = clone $this;
        $fresh->setRawAttributes($this->committedAttributes, true);

        return $fresh;
    }
}
