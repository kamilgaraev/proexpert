<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Database\Eloquent\Model;

class PendingActionStateResolver
{
    public function snapshot(string $toolName, array $arguments, int $organizationId): array
    {
        if (in_array($toolName, ['update_measurement_unit', 'delete_measurement_unit'], true)) {
            return $this->measurementUnitSnapshot($arguments, $organizationId);
        }

        $entity = match ($toolName) {
            'update_schedule_task_status' => $this->findEntity(ScheduleTask::class, 'task_id', $arguments, $organizationId),
            'approve_payment_request' => $this->findEntity(PaymentDocument::class, 'payment_document_id', $arguments, $organizationId),
            'send_project_notification' => $this->findEntity(Project::class, 'project_id', $arguments, $organizationId),
            'create_schedule_task' => $this->findEntity(ProjectSchedule::class, 'schedule_id', $arguments, $organizationId),
            default => null,
        };

        if ($entity !== null) {
            return $entity;
        }

        return [
            'tool_name' => $toolName,
            'arguments' => $this->canonicalize($arguments),
        ];
    }

    public function hash(string $toolName, array $arguments, int $organizationId): string
    {
        return hash('sha256', json_encode(
            $this->snapshot($toolName, $arguments, $organizationId),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private function measurementUnitSnapshot(array $arguments, int $organizationId): array
    {
        $id = filter_var($arguments['id'] ?? null, FILTER_VALIDATE_INT);
        $unit = $id === false || $id === null
            ? null
            : MeasurementUnit::query()
                ->whereKey($id)
                ->where('organization_id', $organizationId)
                ->first();

        return [
            'entity' => 'measurement_unit',
            'id' => $id === false ? null : $id,
            'exists' => $unit instanceof MeasurementUnit,
            'version' => $unit?->updated_at?->format('Y-m-d\\TH:i:s.uP'),
            'attributes' => $unit instanceof MeasurementUnit ? $this->canonicalize([
                'id' => $unit->id,
                'organization_id' => $unit->organization_id,
                'name' => $unit->name,
                'short_name' => $unit->short_name,
                'type' => $unit->type,
                'description' => $unit->description,
                'is_default' => $unit->is_default,
                'is_system' => $unit->is_system,
            ]) : null,
        ];
    }

    private function findEntity(string $modelClass, string $argument, array $arguments, int $organizationId): ?array
    {
        $id = filter_var($arguments[$argument] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id === null) {
            return ['entity' => $modelClass, 'id' => null, 'exists' => false];
        }

        $model = $modelClass::query()
            ->whereKey($id)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $model instanceof Model) {
            return ['entity' => $modelClass, 'id' => $id, 'exists' => false];
        }

        return [
            'entity' => $modelClass,
            'id' => $model->getKey(),
            'exists' => true,
            'version' => $model->getRawOriginal('updated_at'),
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
