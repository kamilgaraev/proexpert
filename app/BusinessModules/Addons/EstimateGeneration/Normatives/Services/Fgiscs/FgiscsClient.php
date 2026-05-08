<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FgiscsDownloadDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FgiscsPricePeriodDTO;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FgiscsClient
{
    private const BASE_URL = 'https://fgiscs.minstroyrf.ru/api';

    /**
     * @return array<int, array{id:int,name:string}>
     */
    public function priceZones(int $subjectId): array
    {
        $response = Http::timeout(60)->get(self::BASE_URL . '/EstimatedPrice/PriceZones', [
            'subjectId' => $subjectId,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('Не удалось получить ценовые зоны ФГИС ЦС.');
        }

        return array_map(static fn (array $item): array => [
            'id' => (int) $item['id'],
            'name' => (string) $item['name'],
        ], $response->json() ?? []);
    }

    /**
     * @return array<int, FgiscsPricePeriodDTO>
     */
    public function periods(int $priceZoneId): array
    {
        $response = Http::timeout(60)->get(self::BASE_URL . '/EstimatedPrice/Periods', [
            'priceZoneId' => $priceZoneId,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('Не удалось получить периоды цен ФГИС ЦС.');
        }

        return array_values(array_filter(array_map(function (array $item): ?FgiscsPricePeriodDTO {
            $parsed = $this->parsePeriodName((string) $item['name']);

            if ($parsed === null) {
                return null;
            }

            return new FgiscsPricePeriodDTO(
                id: (int) $item['id'],
                name: (string) $item['name'],
                year: $parsed['year'],
                quarter: $parsed['quarter'],
            );
        }, $response->json() ?? [])));
    }

    public function downloadWorkerSalary(int $priceZoneId, int $periodId): FgiscsDownloadDTO
    {
        $response = Http::timeout(120)->get(self::BASE_URL . '/EstimatedPrice/RimWorkerSalaryRegistry/Export', [
            'priceZoneId' => $priceZoneId,
            'periodId' => $periodId,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('Не удалось скачать цены труда работников ФГИС ЦС.');
        }

        $content = $response->body();
        $contentType = $response->header('Content-Type');

        if (!str_starts_with($content, 'PK') && !str_contains((string) $contentType, 'spreadsheetml')) {
            throw new RuntimeException('ФГИС ЦС вернул не XLSX-файл с ценами труда.');
        }

        return new FgiscsDownloadDTO(
            content: $content,
            contentType: $contentType,
            fileName: $this->extractFileName($response->header('Content-Disposition')),
        );
    }

    /**
     * @return array{year:int,quarter:int}|null
     */
    private function parsePeriodName(string $name): ?array
    {
        if (preg_match('/([1-4])\s*квартал\s*(\d{4})/ui', $name, $matches) !== 1) {
            return null;
        }

        return [
            'quarter' => (int) $matches[1],
            'year' => (int) $matches[2],
        ];
    }

    private function extractFileName(?string $contentDisposition): ?string
    {
        if ($contentDisposition === null) {
            return null;
        }

        if (preg_match("/filename\\*=UTF-8''([^;]+)/", $contentDisposition, $matches) === 1) {
            return rawurldecode($matches[1]);
        }

        return null;
    }
}
