<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;

enum ObserverProfile: string
{
    case Literal = 'literal';
    case Construction = 'construction';
    case Risk = 'risk';

    public function role(): AiAnalysisRole
    {
        return match ($this) {
            self::Literal => AiAnalysisRole::LiteralObserver,
            self::Construction => AiAnalysisRole::ConstructionObserver,
            self::Risk => AiAnalysisRole::RiskObserver,
        };
    }

    public function promptContractVersion(): string
    {
        return match ($this) {
            self::Literal => LiteralObserverPrompt::VERSION,
            self::Construction => ConstructionObserverPrompt::VERSION,
            self::Risk => RiskObserverPrompt::VERSION,
        };
    }

    public function prompt(): string
    {
        return match ($this) {
            self::Literal => LiteralObserverPrompt::text(),
            self::Construction => ConstructionObserverPrompt::text(),
            self::Risk => RiskObserverPrompt::text(),
        };
    }

    public function promptHash(): string
    {
        return hash('sha256', $this->promptContractVersion()."\0".$this->prompt());
    }

    public function composition(): string
    {
        return match ($this) {
            self::Literal => 'full_page_then_native_text',
            self::Construction => 'full_page_then_vector_context',
            self::Risk => 'full_page_then_related_notes',
        };
    }
}
