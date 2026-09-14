<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSnapshotResponse;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateStructureSnapshotStorage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class EstimateSnapshotResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[DataProvider('encodings')]
    public function test_snapshot_response_preserves_the_complete_payload(string $accept, bool $compressed): void
    {
        $tree = ['sections' => [['id' => 1, 'name' => 'Раздел', 'items' => array_fill(0, 8001, [
            'id' => 42, 'name' => 'Кладка перегородок', 'quantity' => '0.035', 'total_amount' => '4134.53',
            'metadata' => ['raw_data' => ['C' => 'Исходное описание']],
        ])]], 'items' => []];
        $json = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $json);
        rewind($stream);
        $storage = Mockery::mock(EstimateStructureSnapshotStorage::class);
        $storage->shouldReceive('readStream')->once()->with('org-38/estimates/428/snapshot.json')->andReturn($stream);
        $request = Request::create('/');
        $request->headers->set('Accept-Encoding', $accept);
        $response = (new EstimateSnapshotResponse($storage))->create($request, ['id' => 428], 'org-38/estimates/428/snapshot.json');
        ob_start();
        try {
            $response->sendContent();
            $body = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame($compressed ? 'gzip' : null, $response->headers->get('Content-Encoding'));
        self::assertContains('Accept-Encoding', $response->getVary());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertFalse(is_resource($stream));
        if ($compressed) {
            self::assertLessThan(strlen($json) / 20, strlen($body));
            $body = gzdecode($body);
            self::assertIsString($body);
        }
        self::assertSame(['success' => true, 'message' => null, 'data' => ['id' => 428], 'tree' => $tree], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public static function encodings(): array
    {
        return [['gzip, deflate, br', true], ['gzip;q=0', false], ['', false], ['br', false], ['*;q=1, gzip;q=0', false]];
    }
}
