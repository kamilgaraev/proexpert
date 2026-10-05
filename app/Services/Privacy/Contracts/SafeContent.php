<?php

declare(strict_types=1);

namespace App\Services\Privacy\Contracts;

use App\Services\Privacy\SafeRepresentationFactory;
use JsonSerializable;
use LogicException;

final readonly class SafeContent implements JsonSerializable
{
    private function __construct(private string $text)
    {
    }

    public static function prepare(SafeRepresentationFactory $factory, PrivateProjection $projection): PrivacyDecision
    {
        $checked = $factory->verifiedText($projection);

        if (!$checked->isReady()) {
            return $checked;
        }

        $text = $checked->value();

        if (!is_string($text)) {
            return PrivacyDecision::blocked();
        }

        return PrivacyDecision::ready(new self($text));
    }

    public function jsonSerialize(): array
    {
        return ['kind' => 'text', 'text' => ['utf8Text' => $this->text]];
    }

    public function __serialize(): array
    {
        throw new LogicException('safe_content_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('safe_content_deserialization_forbidden');
    }

    private function __clone(): void
    {
    }
}
