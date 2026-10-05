<?php

declare(strict_types=1);

namespace App\Services\Privacy\Contracts;

use App\Services\Privacy\SafeRepresentationFactory;
use JsonSerializable;
use LogicException;

final readonly class SafeRepresentation implements JsonSerializable
{
    private function __construct(
        private SafeContent $content,
        private string $policyVersion,
        private string $representationRef,
        private string $scopeRef,
        private string $sourceGenerationRef,
    ) {
    }

    public static function prepare(SafeRepresentationFactory $factory, PrivateProjection $projection): PrivacyDecision
    {
        $checked = SafeContent::prepare($factory, $projection);

        if (!$checked->isReady()) {
            return $checked;
        }

        $content = $checked->value();

        if (!$content instanceof SafeContent) {
            return PrivacyDecision::blocked();
        }

        return PrivacyDecision::ready(new self(
            $content,
            $projection->context()->policyVersion(),
            'ref_' . bin2hex(random_bytes(16)),
            'ref_' . bin2hex(random_bytes(16)),
            'ref_' . bin2hex(random_bytes(16)),
        ));
    }

    public function jsonSerialize(): array
    {
        return [
            'schemaVersion' => 'safe-representation/1',
            'representationRef' => $this->representationRef,
            'scopeRef' => $this->scopeRef,
            'purpose' => 'assistant_chat',
            'policyVersion' => $this->policyVersion,
            'projectionVersion' => 'private-projection/1',
            'sanitizerVersion' => 'offline-synthetic-fixture/1',
            'sourceGenerationRef' => $this->sourceGenerationRef,
            'content' => $this->content->jsonSerialize(),
        ];
    }

    public function __serialize(): array
    {
        throw new LogicException('safe_representation_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('safe_representation_deserialization_forbidden');
    }

    private function __clone(): void
    {
    }
}
