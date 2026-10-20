<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Http\Resources\RagIndexStatusResource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexStatusService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use App\Models\User;

final readonly class AssistantRagIndexStatusReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_rag_index_status_projection';

    public function __construct(AssistantDataAccessPolicy $access, private AssistantIndexStatusService $status)
    {
        parent::__construct($access);
    }

    public static function hasIntent(string $query): bool
    {
        return preg_match('/баз[а-яё]*\s+знани|индекс[а-яё]*\s+(?:поиск|данн|документ)|индексац|rag/iu', $query) === 1
            && preg_match('/состоян|статус|статистик|очеред|сколько|количеств|готовност|готов[а-яё]*\s+ли/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        $readQuery = self::intentQuery($query);
        $scopeQuery = AssistantRequestProjectScope::organizationWide($query)
            ? preg_replace('/(?<!\pL)(?:(?:во?\s+)?всех|по\s+всем)\s+(?:нашим\s+)?(?:проект|объект)[а-яё]*(?!\pL)/iu', '', $query) ?? $query
            : $query;

        return self::hasIntent($query)
            && ! AssistantOrganizationVerificationReader::hasIntent($readQuery)
            && preg_match('/проект|объект/iu', $scopeQuery) !== 1
            && preg_match('/создай|удали|измени|переиндексируй|переиндексировать|запусти|запускать|обнови|обновляй|сформируй|выгрузи|отправь|подготовь|подготавливай|пересчитай|пересчитывай|согласуй|утверди|подтверди|опубликуй|назначь|добавь|исправь|excel|xlsx|csv|pdf|перескаж|пересказ|цитат|цитир|содержани|что\s+написано|о\s+ч[её]м\s+документ|текст[а-яё]*\s+документ|договор|смет|бюджет|зарплат|оплат|потрач|выручк|прибыл|расход|затрат|марж|рентабел|стоим|(?<!\pL)цен[а-яё]*(?!\pL)|плат[её]ж/iu', $readQuery) !== 1;
    }

    public static function intentQuery(string $query): string
    {
        $query = preg_replace('/подтвержд[её]н[а-яё]*\s+(?=количеств|числ|статистик|показател)/iu', '', $query) ?? $query;

        return preg_replace('/(?:ничего\s+)?не\s+(?:переиндексируй(?:те)?|переиндексировать|обновляй(?:те)?|обновлять|запускай(?:те)?|запускать|меняй(?:те)?|менять)(?!\pL)/iu', '', $query) ?? $query;
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $read = fn (): array => parent::read($actor, $organizationId, $arguments);

        return app()->bound(AssistantRequestExecutionContext::class)
            ? app(AssistantRequestExecutionContext::class)->withOperationBudget($read, 30_000, 'pgsql') : $read();
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $raw = $this->status->status($organizationId, $actor, 'all');
        $status = (new RagIndexStatusResource($raw))->toArray(request());
        if (($raw['status_available'] ?? null) !== true) {
            return ['status_available' => false];
        }
        $data = ['status_available' => true, 'coverage_snapshot_at' => $status['coverage_snapshot_at']];
        foreach (['enabled', 'ready', 'eligible_count_known', 'coverage_complete', 'processing'] as $field) {
            $data[$field] = is_bool($raw[$field] ?? null) ? $raw[$field] : null;
        }
        foreach (['source_count', 'chunk_count', 'expected_source_count', 'indexed_source_count', 'stored_source_count', 'stale_source_count', 'pending_source_count'] as $field) {
            $data[$field] = self::count($raw[$field] ?? null);
        }
        $data['documents'] = [];
        foreach (['total', 'ready', 'pending', 'ocr_required', 'ocr_processing', 'failed', 'unsupported', 'empty'] as $field) {
            $data['documents'][$field] = self::count($status['document_coverage'][$field] ?? null);
        }
        foreach (['latest_run', 'last_successful_run', 'last_failed_run'] as $field) {
            $run = $status[$field];
            $data[$field] = is_array($run) ? array_intersect_key($run, array_flip(['status', 'queued_at', 'started_at', 'finished_at'])) : null;
        }
        $data['catalog_truncated'] = count($status['source_catalog']) > 100;
        $data['source_catalog_error_count'] = count(array_filter($status['source_catalog'], static fn (array $source): bool => ($source['error'] ?? null) !== null && $source['error'] !== ''));
        $data['source_catalog'] = [];
        foreach (array_slice($status['source_catalog'], 0, 100) as $source) {
            $item = array_intersect_key($source, array_flip(['type', 'display_label', 'enabled']));
            $item['has_error'] = ($source['error'] ?? null) !== null && $source['error'] !== '';
            foreach (['expected_count', 'indexed_count', 'stored_count', 'stale_count', 'pending_count'] as $field) {
                $item[$field] = $item['has_error'] ? null : self::count($source[$field] ?? null);
            }
            $data['source_catalog'][] = $item;
        }

        return $data;
    }

    protected function format(array $data): string
    {
        if (! $data['status_available']) {
            return trans_message('ai_assistant_index_status.unavailable')."\n".trans_message('ai_assistant_index_status.read_only');
        }
        $lines = [trans_message('ai_assistant_index_status.scope'),
            trans_message('ai_assistant_index_status.'.($data['ready'] === true ? 'ready' : ($data['ready'] === false ? 'not_ready' : 'ready_unknown')))];
        if (is_string($data['coverage_snapshot_at'])) {
            $lines[] = trans_message('ai_assistant_index_status.snapshot', ['date' => self::text($data['coverage_snapshot_at'])]);
        }
        foreach (['source_count', 'chunk_count', 'expected_source_count', 'indexed_source_count', 'stored_source_count', 'stale_source_count', 'pending_source_count'] as $field) {
            $lines[] = trans_message('ai_assistant_index_status.'.$field).': '.self::number($data[$field]);
        }
        $lines[] = trans_message('ai_assistant_index_status.'.($data['processing'] === true ? 'processing' : ($data['processing'] === false ? 'not_processing' : 'processing_unknown')));
        $lines[] = trans_message('ai_assistant_index_status.queue_scope');
        if ($data['eligible_count_known'] !== true || $data['coverage_complete'] !== true) {
            $lines[] = trans_message('ai_assistant_index_status.coverage_unknown');
        }
        $lines[] = trans_message('ai_assistant_index_status.documents_scope');
        foreach ($data['documents'] as $field => $count) {
            $lines[] = '- '.trans_message('ai_assistant_index_status.documents.'.$field).': '.self::number($count);
        }
        foreach (['latest_run', 'last_successful_run', 'last_failed_run'] as $field) {
            $run = $data[$field];
            $lines[] = trans_message('ai_assistant_index_status.'.$field).': '.($run === null ? trans_message('ai_assistant_index_status.no_run')
                : trans_message('ai_assistant_index_status.run', ['state' => trans_message('ai_assistant_index_status.states.'.(in_array($run['status'], ['queued', 'running', 'succeeded', 'failed'], true) ? $run['status'] : 'unknown')),
                    'date' => self::text((string) ($run['finished_at'] ?? $run['started_at'] ?? $run['queued_at'] ?? trans_message('ai_assistant_index_status.unknown')))]));
        }
        foreach ($data['source_catalog'] as $source) {
            if (! $source['enabled']) {
                continue;
            }
            $lines[] = '- '.self::text($source['display_label'] ?? trans_message('ai_assistant_index_status.category')).': '.self::number($source['stored_count']);
        }
        if ($data['source_catalog_error_count'] > 0) {
            $lines[] = trans_message('ai_assistant_index_status.catalog_errors', ['count' => $data['source_catalog_error_count']]);
        }
        if ($data['catalog_truncated']) {
            $lines[] = trans_message('ai_assistant_index_status.catalog_truncated');
        }
        $lines[] = trans_message('ai_assistant_index_status.read_only');

        return implode("\n", $lines);
    }

    private static function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private static function number(?int $value): string
    {
        return $value === null ? trans_message('ai_assistant_index_status.unknown') : (string) $value;
    }
}
