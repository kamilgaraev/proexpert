<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class AbstractResourceProjectPriceSelector
{
    public function __construct(
        private AbstractNormativeResourcePriceSelector $generic = new AbstractNormativeResourcePriceSelector,
        private ResidentialAbstractResourcePriceSelector $residential = new ResidentialAbstractResourcePriceSelector,
        private ResidentialResourceConversionEligibility $residentialEligibility = new ResidentialResourceConversionEligibility,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $intents
     * @param  list<object>  $candidates
     * @param  list<int>  $baseDatasetIds
     * @return array<string, mixed>|null
     */
    public function select(
        array $intents,
        string $normCode,
        string $normName,
        string $groupCode,
        string $groupName,
        int $regionalPriceVersionId,
        array $candidates,
        array $baseDatasetIds,
    ): ?array {
        if ($this->residentialEligibility->allows($intents, $normCode)) {
            $selection = $this->residential->select($normCode, $groupCode, $candidates, $baseDatasetIds);
            if ($selection !== null) {
                return $selection;
            }
            if ($this->residential->supports($normCode, $groupCode)) {
                return null;
            }
        }

        return $this->generic->select(
            $groupCode,
            $regionalPriceVersionId,
            $candidates,
            $baseDatasetIds,
            $normName,
            $groupName,
        );
    }
}
