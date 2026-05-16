<?php

declare(strict_types=1);

namespace Tests\Unit\Mdm;

use App\BusinessModules\Core\Mdm\Services\MdmSimilarityService;
use PHPUnit\Framework\TestCase;

final class MdmSimilarityServiceTest extends TestCase
{
    public function test_legal_form_noise_does_not_hide_similar_company_names(): void
    {
        $service = new MdmSimilarityService();

        $result = $service->compare('contractor', [
            'name' => 'ООО "Строй-Монтаж"',
            'phone' => '79991234567',
        ], [
            'name' => 'Строй Монтаж',
            'phone' => '79991234567',
        ]);

        $this->assertGreaterThanOrEqual(85, $result['score']);
        $this->assertSame('fuzzy', $result['strategy']);
    }
}
