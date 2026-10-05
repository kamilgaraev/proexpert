<?php

declare(strict_types=1);

namespace App\Services\Privacy\Contracts;

use App\Services\Privacy\PrivateProjectionFactory;
use JsonSerializable;
use LogicException;

final readonly class PrivateProjection implements JsonSerializable
{
    private function __construct(
        private PrivateProjectionFactory $creator,
        private AuthenticatedPrivateContext $context,
        private ProjectionInput $input,
        private array $sources,
        private array $fields,
    ) {
    }

    public static function prepare(PrivateProjectionFactory $factory, ProjectionInput $input): PrivacyDecision
    {
        $checked = $factory->authorize($input);

        if ($checked instanceof PrivacyDecision) {
            return $checked;
        }

        return PrivacyDecision::ready(new self(
            $factory,
            $checked['context'],
            $input,
            $checked['sources'],
            $checked['fields'],
        ));
    }

    public function belongsTo(PrivateProjectionFactory $factory): bool
    {
        return $this->creator === $factory;
    }

    public function context(): AuthenticatedPrivateContext
    {
        return $this->context;
    }

    public function input(): ProjectionInput
    {
        return $this->input;
    }

    public function sources(): array
    {
        return $this->sources;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function matchesCurrent(AuthenticatedPrivateContext $context, array $sources, array $fields): bool
    {
        return $this->context->sameSnapshot($context)
            && $this->sources == $sources
            && $this->fields == $fields;
    }

    public function jsonSerialize(): array
    {
        throw new LogicException('private_serialization_forbidden');
    }

    public function __serialize(): array
    {
        throw new LogicException('private_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('private_deserialization_forbidden');
    }

    public function __debugInfo(): array
    {
        return ['schemaVersion' => 'private-projection/1'];
    }

    private function __clone(): void
    {
    }
}
