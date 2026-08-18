<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\AcceptedDocumentFactProjector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitPublicationFactory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\ProjectiveTransformFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionRoleResultPublicationReplayTest extends TestCase
{
    #[Test]
    public function stored_four_role_results_build_deterministic_publication_without_a_provider_call(): void
    {
        $fixture = json_decode((string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/session-76-document-178-page-1-role-results.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        $source = $fixture['source'];
        $roles = $fixture['role_results'];
        $providerCalls = 0;
        $observers = [];
        foreach (['observer_literal', 'observer_construction', 'observer_risk'] as $role) {
            $observers[$role] = new AiRoleRunResult($roles[$role], null);
        }

        $publication = (new DocumentUnitPublicationFactory)->fromAnalysis(
            $this->input($source),
            $observers,
            new AiRoleRunResult($roles['arbiter'], null),
        );

        self::assertNotNull($publication);
        self::assertSame(0, $providerCalls);
        self::assertCount(3, $publication->claims);
        self::assertCount(3, $publication->decisions);
        $claim = array_values(array_filter(
            $publication->claims,
            static fn ($claim): bool => $claim->factType === 'elevation',
        ))[0];
        self::assertSame('height', (new AcceptedDocumentFactProjector)->project($claim)['fact_type']);
        $replayed = (new DocumentUnitPublicationFactory)->fromAnalysis(
            $this->input($source),
            $observers,
            new AiRoleRunResult($roles['arbiter'], null),
        );
        self::assertNotNull($replayed);
        self::assertSame(
            array_values(array_filter($publication->decisions, static fn ($decision): bool => $decision->claimId === $claim->id))[0]->supportingClaimIds,
            array_values(array_filter($replayed->decisions, static fn ($decision): bool => $decision->claimId === $claim->id))[0]->supportingClaimIds,
        );
    }

    private function input(array $source): VisionDocumentInput
    {
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        $content = (string) ob_get_clean();

        return new VisionDocumentInput(
            $source['organization_id'], $source['project_id'], $source['session_id'], $source['document_id'],
            $source['page_id'], $source['page_number'], $source['processing_unit_id'], $source['source_version'],
            'sha256:'.hash('sha256', $content), 'image/png', $content, 'high',
            new AiOperationContext(
                '11111111-1111-5111-8111-111111111111',
                '22222222-2222-5222-8222-222222222222',
                $source['organization_id'], $source['project_id'], $source['session_id'],
                'understand_documents', 'vision', 1, $source['document_id'], $source['page_id'], $source['processing_unit_id'],
            ),
            (new ProjectiveTransformFactory)->identity(),
        );
    }
}
