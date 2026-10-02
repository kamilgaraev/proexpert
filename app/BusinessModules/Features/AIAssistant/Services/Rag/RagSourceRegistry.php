<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

final class RagSourceRegistry
{
    /**
     * @var array<string, RagSourceCollectorInterface>
     */
    private array $collectors = [];

    /**
     * @param  iterable<RagSourceCollectorInterface>  $collectors
     */
    public function __construct(iterable $collectors)
    {
        foreach ($collectors as $collector) {
            $this->collectors[$collector->sourceType()] = $collector;
        }
    }

    /**
     * @return array<string, RagSourceCollectorInterface>
     */
    public function enabledCollectors(): array
    {
        return array_filter(
            $this->collectors,
            static fn (RagSourceCollectorInterface $collector): bool => $collector->enabled()
        );
    }

    /**
     * @return array<int, string>
     */
    public function enabledSourceTypes(): array
    {
        return array_keys($this->enabledCollectors());
    }

    /**
     * @return array<int, array{type: string, enabled: bool, display_label?: string}>
     */
    public function sourceCatalog(): array
    {
        return array_values(array_map(
            static function (RagSourceCollectorInterface $collector): array {
                $sourceType = $collector->sourceType();
                $source = [
                    'type' => $sourceType,
                    'enabled' => $collector->enabled(),
                ];
                $displayLabel = self::displayLabel($sourceType);

                if ($displayLabel !== null) {
                    $source['display_label'] = $displayLabel;
                }

                return $source;
            },
            $this->enabledCollectors()
        ));
    }

    private static function displayLabel(string $sourceType): ?string
    {
        $translationKey = 'ai_assistant.rag_source_labels.'.$sourceType;

        if (! \Illuminate\Support\Facades\Lang::has($translationKey)) {
            return null;
        }

        $label = trans_message($translationKey);

        if (! is_string($label)) {
            return null;
        }

        $label = trim($label);

        return $label !== '' ? $label : null;
    }

    public function collector(string $sourceType): ?RagSourceCollectorInterface
    {
        return $this->collectors[$sourceType] ?? null;
    }
}
