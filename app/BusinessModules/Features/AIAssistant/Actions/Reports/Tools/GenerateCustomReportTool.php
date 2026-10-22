<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportRenderer;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantGeneratedReportStorage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

final class GenerateCustomReportTool implements AIToolInterface
{
    private array $generated = [];

    public function __construct(private readonly AssistantCustomReportBuilder $builder, private readonly AssistantCustomReportRenderer $renderer,
        private readonly AssistantGeneratedReportStorage $storage, private readonly AIPermissionChecker $permissions) {}

    public function getName(): string
    {
        return 'generate_custom_report';
    }

    public function getDescription(): string
    {
        return trans_message('ai_custom_reports.description');
    }

    public function getParametersSchema(): array
    {
        $string = ['type' => 'string', 'maxLength' => 80];
        $list = ['type' => 'array', 'items' => $string, 'maxItems' => 16];
        $object = static fn (array $properties, array $optional = []): array => ['type' => 'object', 'properties' => $properties,
            'required' => array_values(array_diff(array_keys($properties), $optional)), 'additionalProperties' => false];

        return $object(['title' => ['type' => 'string', 'maxLength' => 120], 'format' => ['type' => 'string', 'enum' => ['xlsx', 'csv', 'pdf']],
            'sections' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'items' => $object([
                'title' => $string, 'domain' => $string, 'entity_type' => $string,
                'scope' => ['type' => 'string', 'enum' => ['organization', 'project'],
                    'description' => 'Область раздела. При сравнении организации с проектами обязательна для каждого раздела: organization для организации, project для явно запрошенного проекта. Заголовок не задаёт область.'],
                'project_id' => ['type' => ['integer', 'null'], 'minimum' => 1], 'columns' => $list,
                'row_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'description' => 'Число строк в результате, например 5 для топ-5. Итоги считаются по всей выборке до ограничения. Максимум 1000 исходных записей.'],
                'filters' => ['type' => 'array', 'maxItems' => 12, 'items' => $object(['field' => $string,
                    'operator' => ['type' => 'string', 'enum' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'in', 'is_null', 'is_not_null']],
                    'value' => ['type' => ['string', 'number', 'boolean', 'array', 'null'], 'items' => ['type' => 'string'], 'maxLength' => 200, 'maxItems' => 50]])],
                'group_by' => ['type' => 'array', 'items' => $string, 'maxItems' => 3],
                'metrics' => ['type' => 'array', 'maxItems' => 8, 'items' => $object(['field' => ['type' => ['string', 'null']],
                    'operation' => ['type' => 'string', 'enum' => ['count', 'sum', 'avg', 'min', 'max']], 'label' => $string])],
                'order_by' => ['type' => 'array', 'maxItems' => 3, 'items' => $object(['field' => $string + ['description' => 'Поле источника; для итогов metric_1, metric_2 и далее по порядку metrics. В сводном отчёте поле группировки или metric_N.'],
                    'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']]])],
            ], ['scope'])]]);
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id
            || ! $this->permissions->canExecuteTool($user, $this->getName(), $arguments)) {
            throw new AccessDeniedHttpException;
        }
        $key = hash('sha256', json_encode([$user->id, $organization->id, $arguments], JSON_THROW_ON_ERROR));
        if (isset($this->generated[$key])) {
            app(\App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService::class)
                ->urlForPath($this->generated[$key]['storage_path'], $user);

            return $this->generated[$key];
        }
        try {
            $report = $this->builder->build($arguments, $user, (int) $organization->id);
            $stream = $this->renderer->render($report);
            try {
                if (app()->bound(\App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext::class)) {
                    app(\App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext::class)->assertCanContinue();
                }
                $title = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/u', '-', $report['title']);
                $filename = trim(mb_substr($title, 0, 100), '. ').'.'.$report['format'];
                $stored = $this->storage->store($stream, $filename, $report['format'], $organization, $user, $report['source_refs'], $report['required_domains']);
            } finally {
                fclose($stream);
            }

            return $this->generated[$key] = ['status' => 'success', 'message' => trans_message('ai_custom_reports.ready'),
                'report_type' => 'custom', 'format' => $report['format'], 'generated_at' => $report['generated_at'],
                'sections' => array_map(static fn (array $section): array => ['title' => $section['title'], 'source_row_count' => $section['source_row_count'],
                    'output_rows' => count($section['rows'])], $report['sections']), ...$stored];
        } catch (ValidationException $exception) {
            return ['status' => 'error', 'message' => trans_message('ai_custom_reports.failed'), 'validation_errors' => $exception->errors()];
        } catch (AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('assistant_custom_report_failed', ['organization_id' => $organization->id, 'actor_id' => $user->id, 'exception_class' => $exception::class]);

            if ($exception instanceof ReportContractException && $exception->errorCode === ReportErrorCode::REPORT_SOURCE_UNAVAILABLE) {
                return ['status' => 'unavailable', 'error_code' => $exception->errorCode->value,
                    'message' => trans_message('reports.errors.report_source_unavailable')];
            }

            return ['status' => 'error', 'message' => trans_message('ai_custom_reports.failed')];
        }
    }
}
