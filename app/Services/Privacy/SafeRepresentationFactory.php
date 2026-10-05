<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Services\Privacy\Contracts\PrivateField;
use App\Services\Privacy\Contracts\PrivateProjection;
use App\Services\Privacy\Contracts\PrivacyCategory;
use App\Services\Privacy\Contracts\PrivacyDecision;
use App\Services\Privacy\Contracts\SafeRepresentation;
use App\Services\Privacy\Contracts\SyntheticFixture;

final class SafeRepresentationFactory
{
    private ?SyntheticFixture $fixture = null;

    public function __construct(private readonly PrivateProjectionFactory $projections)
    {
    }

    public static function forOfflineFixture(PrivateProjectionFactory $projections, SyntheticFixture $fixture): self
    {
        $factory = new self($projections);
        $factory->fixture = $fixture;

        return $factory;
    }

    public function create(PrivateProjection $projection): PrivacyDecision
    {
        return SafeRepresentation::prepare($this, $projection);
    }

    public function verifiedText(PrivateProjection $projection): PrivacyDecision
    {
        $fresh = $this->projections->revalidate($projection);

        if (!$fresh->isReady()) {
            return $fresh;
        }

        foreach ($projection->fields() as $field) {
            if (!$field instanceof PrivateField || $field->effectiveCategory() === PrivacyCategory::Unknown) {
                return PrivacyDecision::blocked('unknown_category');
            }
        }

        if ($this->fixture === null) {
            return PrivacyDecision::blocked('required_stage_unavailable');
        }

        if (!$this->fixture->matches($projection)) {
            return PrivacyDecision::blocked('fixture_mismatch');
        }

        $text = $this->fixture->text();
        $fresh = $this->projections->revalidate($projection);

        return $fresh->isReady() ? PrivacyDecision::ready($text) : $fresh;
    }
}
