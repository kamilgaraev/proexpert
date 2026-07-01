<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Throwable;

final readonly class AssistantReportComposer implements AssistantReportComposerInterface
{
    private const INSUFFICIENT_DATA_MESSAGE = 'данных недостаточно для формирования подтвержденного отчета по найденным источникам.';

    private const SUMMARY_LIMIT = 700;

    private const SOURCE_FACT_LIMIT = 420;

    private const SOURCE_REFERENCE_LIMIT = 180;

    private const RISK_LIMIT = 260;

    private AssistantReportTopicNormalizer $topicNormalizer;

    public function __construct(
        private AssistantReportSourceRetrieverInterface $sourceRetriever,
        private RagPromptContextBuilder $contextBuilder,
        ?AssistantReportTopicNormalizer $topicNormalizer = null
    ) {
        $this->topicNormalizer = $topicNormalizer ?? new AssistantReportTopicNormalizer;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function compose(Organization $organization, User $user, array $input): array
    {
        $query = $this->query($input);
        $projectId = $this->intValue($input['project_id'] ?? null);
        $sources = $this->normalizeSources($this->sources($query, $organization, $user, $input, $projectId));

        if ($sources === []) {
            return $this->insufficientReport($query, $organization, $user, $input);
        }

        $sections = $this->sections($sources);
        $risks = $this->risks($sources);

        return [
            'report_type' => (string) ($input['report_type'] ?? 'rag_report'),
            'title' => $this->topicNormalizer->title($input, $query),
            'topic' => $this->topicNormalizer->topic($input, $query),
            'summary' => $this->summary($sources),
            'key_findings' => $this->keyFindings($sources, $risks),
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
     * @param  array<string, mixed>  $input
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
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function insufficientReport(string $query, Organization $organization, User $user, array $input): array
    {
        return [
            'report_type' => (string) ($input['report_type'] ?? 'rag_report'),
            'title' => $this->topicNormalizer->title($input, $query),
            'topic' => $this->topicNormalizer->topic($input, $query),
            'summary' => self::INSUFFICIENT_DATA_MESSAGE,
            'key_findings' => [],
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
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function sections(array $sources): array
    {
        return array_values(array_map(static fn (array $source): array => [
            'title' => (string) ($source['display_title'] ?? $source['title'] ?? 'Источник'),
            'source_title' => (string) ($source['display_title'] ?? $source['title'] ?? 'Источник'),
            'fact' => (string) ($source['display_excerpt'] ?? ''),
            'excerpt' => (string) ($source['display_excerpt'] ?? ''),
            'items' => [(string) ($source['display_excerpt'] ?? '')],
            'meta' => array_values(is_array($source['meta'] ?? null) ? $source['meta'] : []),
            'type_label' => (string) ($source['type_label'] ?? ''),
            'updated_at_label' => (string) ($source['updated_at_label'] ?? ''),
            'score_label' => (string) ($source['score_label'] ?? ''),
        ], $sources));
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
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
                    $risks[] = $this->truncate($excerpt, self::RISK_LIMIT);
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($risks)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @param  string[]  $risks
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
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function summary(array $sources): string
    {
        $excerpts = array_values(array_filter(array_map(
            static fn (array $source): string => trim((string) ($source['excerpt'] ?? '')),
            array_slice($sources, 0, 3)
        )));

        return 'По найденным источникам: '.$this->truncate(implode(' ', $excerpts), self::SUMMARY_LIMIT);
    }

    /**
     * @param  array<string, mixed>  $input
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
     * @param  array<string, mixed>  $input
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

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSources(array $sources): array
    {
        return array_values(array_map(function (array $source): array {
            $source['display_title'] = $this->truncate($this->sourceTitle($source), 110);
            $source['display_excerpt'] = $this->truncate($this->plainText((string) ($source['excerpt'] ?? '')), self::SOURCE_FACT_LIMIT);
            $source['reference_excerpt'] = $this->truncate($this->plainText((string) ($source['excerpt'] ?? '')), self::SOURCE_REFERENCE_LIMIT);
            $source['type_label'] = $this->sourceTypeLabel($source);
            $source['updated_at_label'] = $this->dateLabel($source['updated_at'] ?? null);
            $source['score_label'] = $this->scoreLabel($source['score'] ?? null);
            $source['meta'] = $this->sourceMeta($source);

            return $source;
        }, $sources));
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @param  string[]  $risks
     * @return string[]
     */
    private function keyFindings(array $sources, array $risks): array
    {
        $findings = [];

        if ($risks !== []) {
            $findings[] = 'В источниках есть признаки рисков: '.$this->truncate($risks[0], 220);
        }

        foreach (array_slice($sources, 0, 3) as $source) {
            $excerpt = trim((string) ($source['display_excerpt'] ?? ''));
            if ($excerpt !== '') {
                $findings[] = $excerpt;
            }
        }

        return array_slice(array_values(array_unique($findings)), 0, 4);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return string[]
     */
    private function sourceMeta(array $source): array
    {
        $meta = [];
        $typeLabel = (string) ($source['type_label'] ?? '');
        $updatedAtLabel = (string) ($source['updated_at_label'] ?? '');
        $scoreLabel = (string) ($source['score_label'] ?? '');

        foreach ([
            $typeLabel !== '' ? 'Тип: '.$typeLabel : '',
            $updatedAtLabel !== '' ? 'Дата: '.$updatedAtLabel : '',
            $scoreLabel !== '' ? 'Релевантность: '.$scoreLabel : '',
        ] as $value) {
            if ($value !== '') {
                $meta[] = $value;
            }
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function sourceTitle(array $source): string
    {
        $title = trim((string) ($source['title'] ?? ''));

        return $title !== '' ? $title : 'Источник базы знаний';
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function sourceTypeLabel(array $source): string
    {
        $type = (string) ($source['source_type'] ?? $source['entity_type'] ?? '');
        $type = trim($type);

        if ($type === '') {
            return '';
        }

        $labels = [
            'project' => 'Проект',
            'schedule' => 'График',
            'contract' => 'Договор',
            'estimate' => 'Смета',
            'estimate_reference' => 'Сметный справочник',
            'purchase_request' => 'Заявка на закупку',
            'supplier_request' => 'Запрос поставщику',
            'supplier_proposal' => 'Предложение поставщика',
            'purchase_order' => 'Заказ поставщику',
            'site_request' => 'Заявка со стройплощадки',
            'completed_work' => 'Выполненные работы',
            'construction_journal' => 'Журнал работ',
            'performance_act' => 'Акт выполненных работ',
            'payment' => 'Платежи',
            'quality_defect' => 'Дефект качества',
            'safety_incident' => 'Инцидент безопасности',
            'warehouse' => 'Склад',
            'machinery' => 'Техника',
            'production_labor' => 'Производство работ',
            'project_pulse' => 'Пульс проекта',
        ];

        return $labels[$type] ?? str_replace('_', ' ', $type);
    }

    private function dateLabel(mixed $value): string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('d.m.Y');
        } catch (Throwable) {
            return '';
        }
    }

    private function scoreLabel(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '';
        }

        $score = (float) $value;
        if ($score <= 0) {
            return '';
        }

        if ($score <= 1) {
            $score *= 100;
        }

        return number_format($score, 0, ',', ' ').'%';
    }

    private function plainText(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;

        return $value;
    }
}
