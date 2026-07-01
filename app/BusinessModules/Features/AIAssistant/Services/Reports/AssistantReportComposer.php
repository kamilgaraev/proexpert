<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use App\Models\Organization;
use App\Models\User;

final readonly class AssistantReportComposer implements AssistantReportComposerInterface
{
    private const INSUFFICIENT_DATA_MESSAGE = 'данных недостаточно для формирования подтвержденного отчета по найденным источникам.';

    public function __construct(
        private AssistantReportSourceRetrieverInterface $sourceRetriever,
        private RagPromptContextBuilder $contextBuilder
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function compose(Organization $organization, User $user, array $input): array
    {
        $query = $this->query($input);
        $projectId = $this->intValue($input['project_id'] ?? null);
        $sources = $this->sources($query, $organization, $user, $input, $projectId);

        if ($sources === []) {
            return $this->insufficientReport($query, $organization, $user, $input);
        }

        $sections = $this->sections($sources);
        $risks = $this->risks($sources);

        return [
            'report_type' => (string) ($input['report_type'] ?? 'rag_report'),
            'title' => $this->title($query),
            'summary' => $this->summary($sources),
            'sections' => $sections,
            'risks' => $risks,
            'next_actions' => $this->nextActions($sources, $risks),
            'sources' => $sources,
            'limitations' => [],
            'has_sufficient_data' => true,
            'period_label' => $this->periodLabel($input),
            'generated_at' => now()->format('d.m.Y H:i'),
            'organization_name' => (string) ($organization->name ?? 'Организация'),
            'generated_by' => $user->name,
            'project_id' => $projectId,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, array<string, mixed>>
     */
    private function sources(string $query, Organization $organization, User $user, array $input, ?int $projectId): array
    {
        $results = $this->sourceRetriever->search($query, (int) $organization->id, $user, array_filter([
            'project_id' => $projectId,
            'date_from' => $input['date_from'] ?? null,
            'date_to' => $input['date_to'] ?? null,
            'report_type' => $input['report_type'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        $context = $this->contextBuilder->build($query, $results);
        $metadata = is_array($context['metadata'] ?? null) ? $context['metadata'] : [];
        $sources = is_array($metadata['sources'] ?? null) ? $metadata['sources'] : [];

        return array_values(array_filter($sources, static fn (mixed $source): bool => is_array($source)));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function insufficientReport(string $query, Organization $organization, User $user, array $input): array
    {
        return [
            'report_type' => (string) ($input['report_type'] ?? 'rag_report'),
            'title' => $this->title($query),
            'summary' => self::INSUFFICIENT_DATA_MESSAGE,
            'sections' => [],
            'risks' => [],
            'next_actions' => [],
            'sources' => [],
            'limitations' => [
                'По запросу не найдено релевантных источников в доступной базе знаний.',
            ],
            'has_sufficient_data' => false,
            'period_label' => $this->periodLabel($input),
            'generated_at' => now()->format('d.m.Y H:i'),
            'organization_name' => (string) ($organization->name ?? 'Организация'),
            'generated_by' => $user->name,
            'project_id' => $this->intValue($input['project_id'] ?? null),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array{title: string, items: array<int, string>}>
     */
    private function sections(array $sources): array
    {
        return array_values(array_map(static fn (array $source): array => [
            'title' => (string) ($source['title'] ?? 'Источник'),
            'items' => [(string) ($source['excerpt'] ?? '')],
        ], $sources));
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return string[]
     */
    private function risks(array $sources): array
    {
        $risks = [];

        foreach ($sources as $source) {
            $excerpt = (string) ($source['excerpt'] ?? '');
            $haystack = mb_strtolower((string) ($source['title'] ?? '').' '.$excerpt);

            foreach (['риск', 'проблем', 'просроч', 'задерж', 'критич', 'дефект', 'инцидент', 'перерасход'] as $marker) {
                if (str_contains($haystack, $marker)) {
                    $risks[] = $excerpt;
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($risks)));
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @param string[] $risks
     * @return string[]
     */
    private function nextActions(array $sources, array $risks): array
    {
        $firstSource = $sources[0]['title'] ?? null;
        if (! is_string($firstSource) || trim($firstSource) === '') {
            return [];
        }

        if ($risks !== []) {
            return ['Проверить источник «'.$firstSource.'» и уточнить ответственного за риск.'];
        }

        return ['Проверить актуальность источника «'.$firstSource.'» перед управленческими решениями.'];
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     */
    private function summary(array $sources): string
    {
        $excerpts = array_values(array_filter(array_map(
            static fn (array $source): string => trim((string) ($source['excerpt'] ?? '')),
            array_slice($sources, 0, 3)
        )));

        return 'По найденным источникам: '.$this->truncate(implode(' ', $excerpts), 700);
    }

    private function title(string $query): string
    {
        return 'Отчет: '.$this->truncate($query, 120);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function query(array $input): string
    {
        foreach (['query', 'topic', 'source_query'] as $key) {
            $value = $input[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return 'отчет по данным базы знаний';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function periodLabel(array $input): string
    {
        $period = $input['period'] ?? null;
        if (is_scalar($period) && trim((string) $period) !== '') {
            return trim((string) $period);
        }

        $dateFrom = $input['date_from'] ?? null;
        $dateTo = $input['date_to'] ?? null;
        if (is_scalar($dateFrom) && is_scalar($dateTo)) {
            return trim((string) $dateFrom).' — '.trim((string) $dateTo);
        }

        return 'весь доступный период';
    }

    private function intValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit - 3)).'...';
    }
}
