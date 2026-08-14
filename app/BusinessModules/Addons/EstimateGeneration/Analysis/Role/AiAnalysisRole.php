<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Role;

enum AiAnalysisRole: string
{
    case LiteralObserver = 'observer_literal';
    case ConstructionObserver = 'observer_construction';
    case RiskObserver = 'observer_risk';
    case Arbiter = 'arbiter';
    case GeometryExpert = 'geometry_expert';
    case ProjectEngineer = 'project_engineer';
    case EstimateComposer = 'estimate_composer';
    case EstimateAuditor = 'estimate_auditor';
}
