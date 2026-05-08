<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FgiscsClientTest extends TestCase
{
    public function test_it_reads_country_subjects_from_fgiscs_contract(): void
    {
        Http::fake([
            'https://fgiscs.minstroyrf.ru/api/EstimatedPrice/CountrySubjects*' => Http::response([
                ['id' => 296, 'name' => 'Республика Татарстан'],
                ['id' => 281, 'name' => 'Алтайский край'],
            ]),
        ]);

        $subjects = (new FgiscsClient())->countrySubjects();

        $this->assertSame([
            ['id' => 296, 'name' => 'Республика Татарстан'],
            ['id' => 281, 'name' => 'Алтайский край'],
        ], $subjects);
    }

    public function test_it_reads_tatarstan_zone_and_periods_from_fgiscs_contract(): void
    {
        Http::fake([
            'https://fgiscs.minstroyrf.ru/api/EstimatedPrice/PriceZones*' => Http::response([
                ['id' => 202, 'name' => 'Республика Татарстан'],
            ]),
            'https://fgiscs.minstroyrf.ru/api/EstimatedPrice/Periods*' => Http::response([
                ['id' => 425, 'name' => '1 квартал 2026 г.'],
                ['id' => 412, 'name' => '4 квартал 2025 г.'],
            ]),
        ]);

        $client = new FgiscsClient();

        $zones = $client->priceZones(296);
        $periods = $client->periods(202);

        $this->assertSame(202, $zones[0]['id']);
        $this->assertSame('Республика Татарстан', $zones[0]['name']);
        $this->assertCount(2, $periods);
        $this->assertSame(425, $periods[0]->id);
        $this->assertSame(2026, $periods[0]->year);
        $this->assertSame(1, $periods[0]->quarter);
    }

    public function test_it_accepts_xlsx_worker_salary_download(): void
    {
        Http::fake([
            'https://fgiscs.minstroyrf.ru/api/EstimatedPrice/RimWorkerSalaryRegistry/Export*' => Http::response(
                "PK\x03\x04",
                200,
                [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' => "attachment; filename*=UTF-8''worker-salary.xlsx",
                ],
            ),
        ]);

        $download = (new FgiscsClient())->downloadWorkerSalary(202, 425);

        $this->assertSame("PK\x03\x04", $download->content);
        $this->assertSame('worker-salary.xlsx', $download->fileName);
    }
}
