<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding;

use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;

final class AssistantRequestUnderstandingResolver
{
    public function resolve(string $message, array $context = []): AssistantRequestUnderstanding
    {
        unset($context);

        $normalized = $this->normalize($message);
        $constraints = $this->resolveConstraints($normalized);
        $requestedEntities = $this->resolveRequestedEntities($normalized);
        $primaryIntent = $this->resolvePrimaryIntent($normalized, $constraints);
        $outputFormat = $this->resolveOutputFormat($normalized, $constraints, $primaryIntent);
        $actionPolicy = $this->resolveActionPolicy($primaryIntent, $constraints);
        $evidence = $this->buildEvidence($normalized, $primaryIntent, $outputFormat, $actionPolicy, $constraints, $requestedEntities);

        return new AssistantRequestUnderstanding(
            primaryIntent: $primaryIntent,
            outputFormat: $outputFormat,
            actionPolicy: $actionPolicy,
            constraints: $constraints,
            requestedEntities: $requestedEntities,
            confidence: $this->resolveConfidence($primaryIntent, $constraints, $evidence),
            evidence: $evidence,
        );
    }

    private function resolveConstraints(string $normalized): array
    {
        $constraints = [];
        $negativeCreation = $this->containsAny($normalized, [
            'не создавай',
            'не формируй',
            'не делай',
            'не генерируй',
            'не выгружай',
            'не подготавливай',
        ]);

        if (
            $this->containsAny($normalized, ['без pdf', 'не нужен pdf', 'не нужно pdf', 'не pdf', 'не файл pdf'])
            || ($negativeCreation && $this->hasPdfMarker($normalized))
        ) {
            $constraints[] = 'no_pdf';
        }

        if (
            $this->containsAny($normalized, ['без файла', 'без файлов', 'не нужен файл', 'не нужны файлы', 'не файл', 'не файлы', 'не нужен никакой файл'])
            || ($negativeCreation && $this->hasFileMarker($normalized))
        ) {
            $constraints[] = 'no_file';
        }

        if (
            $this->containsAny($normalized, ['без отчета', 'без отчетов', 'не нужен отчет', 'не нужны отчеты', 'не отчет', 'не создавай отчет'])
            || ($negativeCreation && $this->hasReportMarker($normalized))
        ) {
            $constraints[] = 'no_report';
        }

        if ($this->containsAny($normalized, ['только текст', 'только текстом', 'просто напиши текстом', 'просто перечисли'])) {
            $constraints[] = 'text_only';
        }

        if ($this->containsAny($normalized, ['строго json', 'только json'])) {
            $constraints[] = 'json_only';
        }

        if ($this->containsAny($normalized, [
            'без действий',
            'не выполняй действий',
            'не выполняй действия',
            'ничего не утверждай',
            'ничего не создавай',
            'ничего не меняй',
            'без изменений',
            'не изменяй',
        ])) {
            $constraints[] = 'no_actions';
        }

        if ($this->containsAny($normalized, ['без навигации', 'не открывай', 'не переходи', 'без переходов'])) {
            $constraints[] = 'no_navigation';
        }

        if ($this->containsAny($normalized, ['источник', 'источники', 'фрагмент', 'фрагменты', 'база знаний', 'базы знаний', 'базе знаний', 'из базы'])) {
            $constraints[] = 'sources_required';
        }

        if (in_array('json_only', $constraints, true)) {
            $constraints = array_values(array_unique([...$constraints, 'no_file']));
        }

        return array_values(array_unique($constraints));
    }

    private function resolveRequestedEntities(string $normalized): array
    {
        $entities = [];

        $map = [
            'project' => ['проект', 'проекты', 'объект', 'объекты', 'стройк'],
            'contract' => ['контракт', 'договор', 'подрядчик'],
            'estimate' => ['смет'],
            'warehouse' => ['склад', 'материал', 'остатк'],
            'payment' => ['платеж', 'платежи', 'оплат', 'счет', 'счёт', 'согласован'],
            'schedule' => ['график', 'срок', 'задач', 'этап'],
        ];

        foreach ($map as $entity => $markers) {
            if ($this->containsAny($normalized, $markers)) {
                $entities[] = $entity;
            }
        }

        return array_values(array_unique($entities));
    }

    private function resolvePrimaryIntent(string $normalized, array $constraints): string
    {
        if ($this->hasConstraint($constraints, ['no_file', 'no_pdf', 'no_report', 'text_only', 'json_only', 'no_actions'])) {
            if ($this->isKnowledgeSearch($normalized)) {
                return 'search_knowledge';
            }

            if ($this->isAnalyticalRequest($normalized)) {
                return 'analyze';
            }

            if ($this->isSummaryRequest($normalized)) {
                return 'summarize';
            }
        }

        if ($this->isApprovalRequest($normalized)) {
            return 'approve';
        }

        if ($this->isNavigationRequest($normalized) && ! in_array('no_navigation', $constraints, true)) {
            return 'navigate';
        }

        if ($this->isExplicitReportGenerationRequest($normalized, $constraints)) {
            return 'generate_report';
        }

        if ($this->isKnowledgeSearch($normalized)) {
            return 'search_knowledge';
        }

        if ($this->isAnalyticalRequest($normalized)) {
            return 'analyze';
        }

        if ($this->isCreateRequest($normalized)) {
            return 'create';
        }

        if ($this->isUpdateRequest($normalized)) {
            return 'update';
        }

        if ($this->isSummaryRequest($normalized)) {
            return 'summarize';
        }

        return 'unknown';
    }

    private function resolveOutputFormat(string $normalized, array $constraints, string $primaryIntent): string
    {
        if (in_array('json_only', $constraints, true)) {
            return 'json';
        }

        if (in_array('text_only', $constraints, true) || $this->hasConstraint($constraints, ['no_file', 'no_pdf', 'no_report'])) {
            return 'text';
        }

        if (str_contains($normalized, 'таблиц')) {
            return 'table';
        }

        if ($primaryIntent === 'generate_report' && $this->hasPdfMarker($normalized)) {
            return 'pdf';
        }

        if ($primaryIntent === 'generate_report' && $this->hasFileMarker($normalized)) {
            return 'file';
        }

        if (in_array($primaryIntent, ['search_knowledge', 'summarize', 'analyze'], true)) {
            return 'text';
        }

        return 'any';
    }

    private function resolveActionPolicy(string $primaryIntent, array $constraints): string
    {
        if ($this->hasConstraint($constraints, ['no_actions', 'text_only', 'json_only'])) {
            return 'read_only';
        }

        if ($primaryIntent === 'generate_report') {
            return 'allow_file_generation';
        }

        if ($primaryIntent === 'navigate') {
            return 'allow_navigation';
        }

        if (in_array($primaryIntent, ['create', 'update', 'approve'], true)) {
            return 'requires_confirmation';
        }

        return 'read_only';
    }

    private function buildEvidence(
        string $normalized,
        string $primaryIntent,
        string $outputFormat,
        string $actionPolicy,
        array $constraints,
        array $requestedEntities
    ): array {
        $evidence = [
            [
                'type' => 'intent',
                'value' => $primaryIntent,
            ],
            [
                'type' => 'output_format',
                'value' => $outputFormat,
            ],
            [
                'type' => 'action_policy',
                'value' => $actionPolicy,
            ],
        ];

        foreach ($constraints as $constraint) {
            $evidence[] = [
                'type' => 'constraint',
                'value' => $constraint,
            ];
        }

        foreach ($requestedEntities as $entity) {
            $evidence[] = [
                'type' => 'entity',
                'value' => $entity,
            ];
        }

        if ($this->isKnowledgeSearch($normalized)) {
            $evidence[] = [
                'type' => 'marker',
                'value' => 'knowledge_search',
            ];
        }

        return $evidence;
    }

    private function resolveConfidence(string $primaryIntent, array $constraints, array $evidence): float
    {
        $confidence = $primaryIntent === 'unknown' ? 0.45 : 0.62;
        $confidence += min(count($constraints) * 0.06, 0.18);
        $confidence += min(count($evidence) * 0.015, 0.15);

        return min(0.95, round($confidence, 2));
    }

    private function isExplicitReportGenerationRequest(string $normalized, array $constraints): bool
    {
        if ($this->hasConstraint($constraints, ['no_file', 'no_pdf', 'no_report', 'text_only', 'json_only', 'no_actions'])) {
            return false;
        }

        return $this->containsAny($normalized, [
            'сформируй отчет',
            'сформируй pdf',
            'создай pdf',
            'создай отчет',
            'сделай отчет',
            'подготовь отчет',
            'подготовь файл',
            'выгрузи файл',
            'выгрузи отчет',
            'выгрузи pdf',
            'скачай отчет',
            'нужен отчет',
            'нужен pdf',
            'покажи отчет',
        ]);
    }

    private function isKnowledgeSearch(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'база знаний',
            'базы знаний',
            'базе знаний',
            'из базы',
            'найди факты',
            'перечисли факты',
            'перечисли источники',
            'покажи фрагменты',
            'источники',
            'фрагменты',
        ]);
    }

    private function isAnalyticalRequest(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'проанализируй',
            'анализ',
            'риски',
            'риск',
            'проблем',
            'расхожд',
            'сравни',
            'что критично',
            'требуют согласования',
        ]);
    }

    private function isSummaryRequest(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'сводку',
            'сводка',
            'расскажи',
            'покажи',
            'перечисли',
            'напиши',
        ]);
    }

    private function isApprovalRequest(string $normalized): bool
    {
        if ($this->containsAny($normalized, ['ничего не утверждай', 'не утверждай', 'не согласуй', 'не одобряй'])) {
            return false;
        }

        return $this->containsAny($normalized, ['утверди', 'согласуй', 'одобри', 'подтверди платеж']);
    }

    private function isNavigationRequest(string $normalized): bool
    {
        return $this->containsAny($normalized, ['открой', 'перейди', 'покажи раздел', 'открой проект']);
    }

    private function isCreateRequest(string $normalized): bool
    {
        return $this->containsAny($normalized, ['создай задачу', 'создай заявку', 'создай проект']);
    }

    private function isUpdateRequest(string $normalized): bool
    {
        return $this->containsAny($normalized, ['измени', 'обнови', 'назначь', 'перенеси']);
    }

    private function hasPdfMarker(string $normalized): bool
    {
        return str_contains($normalized, 'pdf') || str_contains($normalized, 'пдф');
    }

    private function hasFileMarker(string $normalized): bool
    {
        return $this->containsAny($normalized, ['файл', 'файлы', 'файлов', 'выгруз']);
    }

    private function hasReportMarker(string $normalized): bool
    {
        return $this->containsAny($normalized, ['отчет', 'отчета', 'отчеты', 'отчетов']);
    }

    private function hasConstraint(array $constraints, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (in_array($needle, $constraints, true)) {
                return true;
            }
        }

        return false;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (is_string($needle) && $needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace('ё', 'е', $value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    }
}
