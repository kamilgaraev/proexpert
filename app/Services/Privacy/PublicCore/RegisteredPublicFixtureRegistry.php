<?php

declare(strict_types=1);

namespace App\Services\Privacy\PublicCore;

use LogicException;

final readonly class RegisteredPublicFixtureRegistry
{
    private array $fixtures;

    private function __construct()
    {
        $this->fixtures = [
            'material-search-v1' => [
                'version' => 'public-material/1',
                'generation' => 'ref_0b8c4d85d5d246598fac886dac770d20',
                'corpus' => 'material-search-v1',
                'records' => [
                    ['id' => 'concrete-b25', 'title' => 'Бетон товарный В25 М350', 'unit' => 'm3', 'price' => '7800.00', 'currency' => 'RUB', 'price_basis' => 'm3', 'vat' => '20%'],
                    ['id' => 'concrete-b30', 'title' => 'Бетон товарный В30 М400', 'unit' => 'm3', 'price' => '8250.50', 'currency' => 'RUB', 'price_basis' => 'm3', 'vat' => null],
                    ['id' => 'concrete-b15', 'title' => 'Смесь бетонная В15 М200', 'unit' => 'm3', 'price' => '6500.00', 'currency' => null, 'price_basis' => null, 'vat' => null],
                    ['id' => 'waterproofing', 'title' => 'Гидроизоляция для бетона', 'unit' => 'm2', 'price' => '350.00', 'currency' => 'RUB', 'price_basis' => 'm2', 'vat' => '20%'],
                    ['id' => 'lintel', 'title' => 'Перемычка железобетонная', 'unit' => 'item', 'price' => '1900.00', 'currency' => 'RUB', 'price_basis' => 'item', 'vat' => '20%'],
                    ['id' => 'cement-m500', 'title' => 'Цемент М500', 'unit' => 'kg', 'price' => '16.40', 'currency' => 'RUB', 'price_basis' => 'kg', 'vat' => null],
                ],
                'inputs' => [
                    'price-b25' => ['Сколько стоит бетон В25 за м³?', ['price-b25', 'quote-12m3', 'cement-price', 'no-results']],
                    'quote-12m3' => ['Рассчитай стоимость 12 м³ бетона В25 по учебному каталогу.', ['quote-12m3', 'cement-price', 'no-results']],
                    'cement-price' => ['Найди цену цемента М500 за килограмм.', ['cement-price', 'price-b25', 'no-results']],
                    'no-results' => ['Найди несуществующий материал в учебном каталоге.', ['no-results', 'price-b25']],
                ],
                'media' => false,
            ],
            'public-photo-metadata-v1' => [
                'version' => 'public-photo-metadata/1',
                'generation' => 'ref_4a1efc3fd3634e60b131fb8e58db3e65',
                'corpus' => 'public-photo-metadata-v1',
                'records' => [
                    ['id' => 'training-photo-transcript', 'text' => 'Учебная схема: пункт 1 — бетонная опора; пункт 2 — арматурный каркас. Это текстовая расшифровка, не проверка пикселей изображения.'],
                ],
                'inputs' => [
                    'photo-explain' => ['Объясни выбранную учебную расшифровку фото.', ['photo-explain', 'photo-followup-one', 'photo-followup-two']],
                    'photo-followup-one' => ['Поясни второй пункт выбранной учебной расшифровки.', ['photo-followup-one', 'photo-followup-two']],
                    'photo-followup-two' => ['Я про учебную схему: что значит строка про арматурный каркас?', ['photo-followup-two']],
                ],
                'media' => false,
            ],
        ];
    }

    public static function compiled(): self
    {
        return new self();
    }

    public function resolve(string $fixtureId, string $fixtureVersion, string $inputId): ?array
    {
        $fixture = $this->fixtures[$fixtureId] ?? null;
        if ($fixture === null || $fixture['version'] !== $fixtureVersion || !isset($fixture['inputs'][$inputId])) {
            return null;
        }

        $row = [
            'fixture_id' => $fixtureId,
            'fixture_version' => $fixtureVersion,
            'input_id' => $inputId,
            'scenario_id' => $fixtureId . '/' . $inputId,
            'scenario_version' => 'public-core-scenario/1',
            'scenario_step' => $inputId,
            'display_text' => $fixture['inputs'][$inputId][0],
            'source_generation_ref' => $fixture['generation'],
            'corpus_id' => $fixture['corpus'],
            'scenario_order' => $fixture['inputs'][$inputId][1],
            'canonical_question_sha256' => hash('sha256', $fixture['inputs'][$inputId][0]),
        ];
        $row['record_sha256'] = hash('sha256', self::canonical($row));
        return $row;
    }

    public function fixtureDigest(string $fixtureId, string $version): ?string
    {
        $fixture = $this->fixtures[$fixtureId] ?? null;
        return $fixture !== null && $fixture['version'] === $version ? hash('sha256', self::canonical($fixture)) : null;
    }

    public function records(string $fixtureId, string $version): ?array
    {
        $fixture = $this->fixtures[$fixtureId] ?? null;
        return $fixture !== null && $fixture['version'] === $version ? $fixture['records'] : null;
    }

    public function catalog(): array
    {
        $catalog = [];
        foreach ($this->fixtures as $fixtureId => $fixture) {
            foreach (array_keys($fixture['inputs']) as $inputId) {
                $catalog[] = $this->resolve($fixtureId, $fixture['version'], $inputId);
            }
        }
        return $catalog;
    }

    public function manifest(): array
    {
        return [
            'schemaVersion' => 'public-core-fixture-registry/1',
            'registryVersion' => 'public-core-registry/0.1',
            'catalog' => $this->catalog(),
            'fixtures' => $this->fixtures,
        ];
    }

    public function manifestDigest(): string
    {
        return hash('sha256', self::canonical($this->manifest()));
    }

    public static function canonical(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function __serialize(): array
    {
        throw new LogicException('public_registry_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('public_registry_deserialization_forbidden');
    }

    private function __clone(): void
    {
    }
}
