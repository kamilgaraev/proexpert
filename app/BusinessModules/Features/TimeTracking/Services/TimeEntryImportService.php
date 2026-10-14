<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Services;

use App\Models\Organization;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeTrackingService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

final class TimeEntryImportService
{
    private const FIELDS = ['user_id', 'worker_type', 'worker_name', 'worker_count', 'project_id', 'work_type_id', 'task_id', 'work_date', 'start_time', 'end_time', 'hours_worked', 'break_time', 'title', 'description', 'is_billable', 'timezone', 'start_at', 'end_at'];

    public function __construct(private readonly TimeTrackingService $entries) {}

    /** Uploaded file stays in PHP's temporary upload directory; no domain file is persisted. */
    public function preview(int $organizationId, User $actor, UploadedFile $file, array $mapping): array
    {
        $this->entries->authorizeImport($organizationId, $actor);
        if (! $file->isValid() || $file->getSize() > 5 * 1024 * 1024 || $mapping === []
            || array_diff(array_keys($mapping), self::FIELDS) !== [] || count(array_unique(array_values($mapping))) !== count($mapping)) {
            throw ValidationException::withMessages(['file' => trans_message('time_tracking.import.invalid_file')]);
        }
        $path = $file->getRealPath();
        try {
            $type = IOFactory::identify($path);
            if (! in_array($type, ['Xlsx', 'Xls'], true)) {
                throw new DomainException(trans_message('time_tracking.import.invalid_file'));
            }
            $reader = IOFactory::createReader($type);
            $info = $reader->listWorksheetInfo($path);
            if (count($info) !== 1 || $info[0]['totalRows'] > 501 || $info[0]['totalColumns'] > 50) {
                throw new DomainException(trans_message('time_tracking.import.limit_exceeded'));
            }
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (DomainException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['file' => trans_message('time_tracking.import.invalid_file')]);
        }
        try {
            $sheet = $book->getSheet(0);
            $headers = $sheet->rangeToArray('A1:'.$info[0]['lastColumnLetter'].'1', null, false, false)[0];
            $headers = array_map(static fn ($value): string => trim((string) $value), $headers);
            $columns = [];
            foreach ($mapping as $field => $header) {
                $matches = array_keys($headers, trim((string) $header), true);
                if (count($matches) !== 1) {
                    throw ValidationException::withMessages(['mapping' => trans_message('time_tracking.import.invalid_mapping')]);
                }
                $columns[$field] = $matches[0];
            }
            $rows = [];
            $seen = [];
            for ($number = 2; $number <= $info[0]['totalRows']; $number++) {
                $cells = $sheet->rangeToArray('A'.$number.':'.$info[0]['lastColumnLetter'].$number, null, false, false)[0];
                if (count(array_filter($cells, static fn ($cell): bool => $cell !== null && $cell !== '')) === 0) {
                    continue;
                }
                $data = ['organization_id' => $organizationId, 'status' => 'draft', 'worker_type' => 'user', 'is_billable' => true];
                foreach ($columns as $field => $column) {
                    $value = $cells[$column] ?? null;
                    if ($value === null || $value === '') {
                        continue;
                    }
                    if (is_string($value) && str_starts_with($value, '=')) {
                        $data[$field] = $value;

                        continue;
                    }
                    if (is_numeric($value) && in_array($field, ['work_date', 'start_time', 'end_time', 'start_at', 'end_at'], true)) {
                        $format = $field === 'work_date' ? 'Y-m-d' : (str_ends_with($field, '_time') ? 'H:i' : 'Y-m-d H:i:s');
                        $value = Date::excelToDateTimeObject((float) $value)->format($format);
                    }
                    $data[$field] = is_string($value) ? trim($value) : $value;
                }
                $errors = [];
                if (count(array_filter($cells, static fn ($cell): bool => is_string($cell) && str_starts_with($cell, '='))) > 0) {
                    $errors['row'] = [trans_message('time_tracking.import.formulas_not_allowed')];
                } else {
                    try {
                        $data = $this->validateRow($data);
                        $fingerprint = $this->fingerprint($data);
                        if (isset($seen[$fingerprint]) || $this->duplicate($organizationId, $fingerprint, $data)) {
                            $errors['row'] = [trans_message('time_tracking.import.duplicate_row')];
                        }
                        $seen[$fingerprint] = true;
                    } catch (ValidationException $exception) {
                        $errors = $exception->errors();
                    } catch (DomainException $exception) {
                        $errors['row'] = [$exception->getMessage()];
                    }
                }
                $rows[] = ['row' => $number, 'data' => $data, 'errors' => $errors];
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => trans_message('time_tracking.import.empty_file')]);
            }
            $token = (string) Str::uuid();
            $preview = ['preview_token' => $token, 'rows' => $rows, 'total_rows' => count($rows), 'valid_rows' => count(array_filter($rows, static fn ($row): bool => $row['errors'] === []))];
            $preview['can_commit'] = $preview['valid_rows'] === $preview['total_rows'];
            Cache::put($this->key($organizationId, (int) $actor->id, $token), $preview, now()->addMinutes(30));

            return $preview;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function commit(int $organizationId, User $actor, string $token): array
    {
        $this->entries->authorizeImport($organizationId, $actor);
        $key = $this->key($organizationId, (int) $actor->id, $token);

        return DB::transaction(function () use ($organizationId, $key): array {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $preview = Cache::get($key);
            if (! is_array($preview)) {
                throw new DomainException(trans_message('time_tracking.import.preview_expired'));
            }
            if (isset($preview['result'])) {
                return $preview['result'];
            }
            $previousIds = TimeEntry::query()->where('organization_id', $organizationId)->where('custom_fields->time_import->preview_token', $preview['preview_token'])->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            if (count($previousIds) === $preview['total_rows']) {
                return ['created_count' => count($previousIds), 'entry_ids' => $previousIds];
            }
            if (! $preview['can_commit']) {
                throw ValidationException::withMessages(['rows' => trans_message('time_tracking.import.row_errors')]);
            }
            $ids = [];
            foreach ($preview['rows'] as $row) {
                $data = $this->validateRow($row['data']);
                $fingerprint = $this->fingerprint($data);
                if ($this->duplicate($organizationId, $fingerprint, $data)) {
                    throw ValidationException::withMessages(['rows.'.$row['row'] => trans_message('time_tracking.import.duplicate_row')]);
                }
                $data['custom_fields'] = array_replace($data['custom_fields'] ?? [], ['time_import' => ['fingerprint' => $fingerprint, 'row' => $row['row'], 'preview_token' => $preview['preview_token']]]);
                $ids[] = (int) $this->entries->createTimeEntry($data)->id;
            }
            $result = ['created_count' => count($ids), 'entry_ids' => $ids];
            DB::afterCommit(static function () use ($key, $preview, $result): void {
                Cache::put($key, [...$preview, 'result' => $result], now()->addMinutes(30));
            });

            return $result;
        });
    }

    private function validateRow(array $data): array
    {
        $validator = Validator::make($data, [
            'organization_id' => ['required', 'integer'], 'project_id' => ['required', 'integer'],
            'worker_type' => ['required', 'in:user,virtual,brigade,equipment'],
            'user_id' => ['required_if:worker_type,user', 'nullable', 'integer'],
            'worker_name' => ['required_unless:worker_type,user', 'nullable', 'string', 'max:255'],
            'worker_count' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'work_type_id' => ['nullable', 'integer'], 'task_id' => ['nullable', 'integer'],
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'start_time' => ['nullable', 'date_format:H:i'], 'end_time' => ['nullable', 'date_format:H:i'],
            'start_at' => ['nullable', 'date'], 'end_at' => ['nullable', 'date'], 'timezone' => ['nullable', 'timezone'],
            'hours_worked' => ['required_without_all:start_at,start_time', 'nullable', 'numeric', 'min:0.01', 'max:24'],
            'break_time' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:1000'],
            'is_billable' => ['boolean'], 'status' => ['in:draft'],
        ]);
        $validator->validate();

        return $this->entries->validateImportRow($data);
    }

    private function fingerprint(array $data): string
    {
        $canonical = array_intersect_key($data, array_flip(self::FIELDS));
        foreach (['user_id', 'worker_count', 'project_id', 'work_type_id', 'task_id'] as $field) {
            if (isset($canonical[$field])) {
                $canonical[$field] = (int) $canonical[$field];
            }
        }
        foreach (['hours_worked', 'break_time'] as $field) {
            if (isset($canonical[$field])) {
                $canonical[$field] = number_format((float) $canonical[$field], 8, '.', '');
            }
        }
        if (isset($canonical['is_billable'])) {
            $canonical['is_billable'] = (bool) $canonical['is_billable'];
        }
        ksort($canonical);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function duplicate(int $organizationId, string $fingerprint, array $data): bool
    {
        if (TimeEntry::query()->where('organization_id', $organizationId)->where('custom_fields->time_import->fingerprint', $fingerprint)->exists()) {
            return true;
        }
        $query = TimeEntry::query()->where('organization_id', $organizationId)
            ->where('project_id', (int) $data['project_id'])->whereBetween('work_date', [
                (new \DateTimeImmutable($data['work_date']))->modify('-2 days')->format('Y-m-d'),
                (new \DateTimeImmutable($data['work_date']))->modify('+2 days')->format('Y-m-d'),
            ])
            ->where('worker_type', $data['worker_type'] ?? 'user')->where('title', $data['title']);
        if (($data['worker_type'] ?? 'user') === 'user') {
            $query->where('user_id', (int) $data['user_id']);
        } else {
            $query->where('worker_name', $data['worker_name']);
        }
        foreach (['work_type_id', 'task_id', 'worker_count'] as $field) {
            $query->where($field, $data[$field] ?? null);
        }
        foreach ($query->get() as $entry) {
            $sameDuration = isset($data['net_minutes']) && $entry->net_minutes !== null
                ? abs((float) $data['net_minutes'] - (float) $entry->net_minutes) < 0.00000001
                : abs((float) ($data['hours_worked'] ?? 0) - (float) $entry->hours_worked) < 0.000001;
            if (! empty($data['start_at']) && ! empty($data['end_at']) && $entry->start_at !== null && $entry->end_at !== null
                && (new \DateTimeImmutable($data['start_at']))->getTimestamp() === $entry->start_at->getTimestamp()
                && (new \DateTimeImmutable($data['end_at']))->getTimestamp() === $entry->end_at->getTimestamp()) {
                return true;
            }
            if ($entry->work_date->format('Y-m-d') !== $data['work_date']) {
                continue;
            }
            if ($sameDuration && empty($data['start_time']) && empty($entry->getRawOriginal('start_time'))
                && empty($data['end_time']) && empty($entry->getRawOriginal('end_time'))) {
                return true;
            }
            if (! empty($data['start_time']) && ! empty($data['end_time'])
                && ($entry->start_at === null || $entry->end_at === null)
                && substr((string) ($data['start_time'] ?? ''), 0, 5) === substr((string) $entry->getRawOriginal('start_time'), 0, 5)
                && substr((string) ($data['end_time'] ?? ''), 0, 5) === substr((string) $entry->getRawOriginal('end_time'), 0, 5)) {
                return true;
            }
        }

        return false;
    }

    private function key(int $organizationId, int $userId, string $token): string
    {
        return 'time-entry-import:'.$organizationId.':'.$userId.':'.$token;
    }
}
