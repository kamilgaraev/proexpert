<?php

declare(strict_types=1);

namespace App\Services\Privacy\Contracts;

use App\Services\Privacy\PrivateProjectionFactory;
use JsonSerializable;
use LogicException;

final readonly class PrivateProjection implements JsonSerializable
{
    private array $sources;

    private function __construct(
        private PrivateProjectionFactory $creator,
        private AuthenticatedPrivateContext $context,
        private ProjectionInput $input,
        array $sources,
        private array $fields,
    ) {
        $ownedSources = [];
        foreach ($sources as $source) {
            $ownedSources[] = $source;
        }
        $this->sources = $ownedSources;
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
        if (!$this->context->sameSnapshot($context)
            || !array_is_list($sources)
            || !array_is_list($fields)
            || count($this->sources) !== count($sources)
            || count($this->fields) !== count($fields)) {
            return false;
        }

        foreach ($this->sources as $index => $source) {
            $current = $sources[$index];
            if (!$source instanceof PrivateSourceVersion
                || !$current instanceof PrivateSourceVersion
                || !$source->sameSnapshot($current)) {
                return false;
            }
        }

        foreach ($this->fields as $index => $field) {
            $current = $fields[$index];
            if (!$field instanceof PrivateField
                || !$current instanceof PrivateField
                || !$field->sameValue($current)) {
                return false;
            }
        }

        return true;
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
