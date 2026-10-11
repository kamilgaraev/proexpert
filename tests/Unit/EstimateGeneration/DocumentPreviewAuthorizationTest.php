<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\EstimateGenerationDocumentPreviewService;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Mockery;
use PHPUnit\Framework\TestCase;

final class DocumentPreviewAuthorizationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_cached_permissions_cannot_create_a_link_when_current_authorization_is_denied(): void
    {
        [$actor, $document] = $this->fixture();
        $cached = Mockery::mock(AuthorizationService::class);
        $cached->shouldNotReceive('can');
        $fresh = Mockery::mock(EstimateGenerationActionAuthorization::class);
        $fresh->expects('authorize')->once()->with($actor, $document->session, 'estimate_generation.view')
            ->andThrow(new AuthorizationException);
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('temporaryUrl');
        self::assertNull((new EstimateGenerationDocumentPreviewService($cached, $files, $fresh))->forDocument($document, $actor));
    }

    public function test_source_link_is_signed_only_after_current_scoped_authorization(): void
    {
        [$actor, $document] = $this->fixture();
        $cached = Mockery::mock(AuthorizationService::class);
        $cached->shouldNotReceive('can');
        $authorized = false;
        $fresh = Mockery::mock(EstimateGenerationActionAuthorization::class);
        $fresh->expects('authorize')->once()->with($actor, $document->session, 'estimate_generation.view')
            ->andReturnUsing(static function () use (&$authorized): void {
                $authorized = true;
            });
        $files = Mockery::mock(FileService::class);
        $files->expects('temporaryUrl')->once()->withArgs(static function (string $path, int $minutes, $organization, array $options) use (&$authorized): bool {
            self::assertTrue($authorized);

            return $path === 'org-7/estimate-generation/sessions/41/documents/plan.pdf' && $minutes === 5
                && $organization->id === 7 && $options['ResponseContentType'] === 'application/pdf';
        })->andReturn('https://storage.example/source');
        self::assertSame('https://storage.example/source', (new EstimateGenerationDocumentPreviewService($cached, $files, $fresh))->forDocument($document, $actor));
    }

    private function fixture(): array
    {
        $actor = new User;
        $actor->forceFill(['id' => 5, 'current_organization_id' => 7]);
        $session = new EstimateGenerationSession;
        $session->forceFill(['id' => 41, 'organization_id' => 7, 'project_id' => 17]);
        $document = new EstimateGenerationDocument;
        $document->forceFill(['id' => 91, 'session_id' => 41, 'organization_id' => 7, 'project_id' => 17, 'status' => 'processed',
            'filename' => 'plan.pdf', 'mime_type' => 'application/pdf', 'storage_path' => 'org-7/estimate-generation/sessions/41/documents/plan.pdf']);
        $document->setRelation('session', $session);

        return [$actor, $document];
    }
}
