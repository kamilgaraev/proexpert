<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrAccess;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class TimekeepingPolicyService
{
    public function __construct(private readonly HrAccess $access) {}

    public function forOrganization(int $organizationId): array
    {
        $record = DB::table('workforce_timekeeping_policies')->where('organization_id', $organizationId)->first();
        $settings = $record === null ? [] : json_decode((string) $record->settings, true, 512, JSON_THROW_ON_ERROR);
        $result = array_replace_recursive((array) config('workforce.timekeeping', []), $settings);
        foreach ($settings['calendars'] ?? [] as $profile => $years) {
            foreach ($years as $year => $calendar) {
                $result['calendars'][$profile][$year] = $calendar;
            }
        }

        return $result;
    }

    public function show(int $organizationId, ?User $actor): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.settings.manage');

        return ['revision' => (int) (DB::table('workforce_timekeeping_policies')->where('organization_id', $organizationId)->value('revision') ?? 0),
            'settings' => $this->forOrganization($organizationId)];
    }

    public function update(int $organizationId, ?User $actor, array $input): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.settings.manage');
        $data = Validator::make($input, [
            'expected_revision' => ['required', 'integer', 'min:0'],
            'settings' => ['required', 'array:form,retention,legal_review_required,legal_review_reference,verified_at,calendars'],
            'settings.form' => ['required', 'array:version,approved_basis_reference,approved_at'],
            'settings.form.version' => ['required', 'string', 'max:80'],
            'settings.form.approved_basis_reference' => ['required', 'string', 'max:255'],
            'settings.form.approved_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'settings.retention' => ['required', 'array:approved_basis_reference,ordinary_years,hazardous_years,pre2003_hazardous_years'],
            'settings.retention.approved_basis_reference' => ['required', 'string', 'max:255'],
            'settings.retention.ordinary_years' => ['sometimes', 'integer', 'min:5', 'max:150'],
            'settings.retention.hazardous_years' => ['sometimes', 'integer', 'min:50', 'max:150'],
            'settings.retention.pre2003_hazardous_years' => ['sometimes', 'integer', 'min:75', 'max:150'],
            'settings.legal_review_required' => ['required', 'boolean'],
            'settings.legal_review_reference' => ['required_if:settings.legal_review_required,false', 'nullable', 'string', 'max:255'],
            'settings.verified_at' => ['required_if:settings.legal_review_required,false', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'settings.calendars' => ['sometimes', 'array', 'max:50'],
            'settings.calendars.*' => ['array', 'max:30'],
            'settings.calendars.*.*' => ['array:version,source,holidays,shortened_days,working_days'],
            'settings.calendars.*.*.version' => ['required_with:settings.calendars', 'string', 'max:120'],
            'settings.calendars.*.*.source' => ['required_with:settings.calendars', 'string', 'max:1000'],
            'settings.calendars.*.*.holidays' => ['present_with:settings.calendars', 'array', 'max:370'],
            'settings.calendars.*.*.holidays.*' => ['date_format:Y-m-d', 'distinct'],
            'settings.calendars.*.*.shortened_days' => ['present_with:settings.calendars', 'array', 'max:370'],
            'settings.calendars.*.*.shortened_days.*' => ['date_format:Y-m-d', 'distinct'],
            'settings.calendars.*.*.working_days' => ['present_with:settings.calendars', 'array', 'max:370'],
            'settings.calendars.*.*.working_days.*' => ['date_format:Y-m-d', 'distinct'],
        ])->validate();

        foreach ($data['settings']['calendars'] ?? [] as $profile => $years) {
            foreach ($years as $year => $calendar) {
                $year = (string) $year;
                if (! preg_match('/^[1-9][0-9]{3}$/D', $year)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "settings.calendars.{$profile}.{$year}" => trans_message('workforce.errors.timekeeping_calendar_year_mismatch'),
                    ]);
                }
                foreach (['holidays', 'shortened_days', 'working_days'] as $kind) {
                    foreach ($calendar[$kind] as $index => $date) {
                        if (substr($date, 0, 4) !== $year) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                "settings.calendars.{$profile}.{$year}.{$kind}.{$index}" => trans_message('workforce.errors.timekeeping_calendar_year_mismatch'),
                            ]);
                        }
                    }
                }
            }
        }

        return DB::transaction(function () use ($organizationId, $actor, $data): array {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $record = DB::table('workforce_timekeeping_policies')->where('organization_id', $organizationId)->lockForUpdate()->first();
            if ((int) ($record->revision ?? 0) !== (int) $data['expected_revision']) {
                throw new DomainException(trans_message('workforce.errors.timekeeping_revision_conflict'));
            }
            DB::table('workforce_timekeeping_policies')->updateOrInsert(['organization_id' => $organizationId], [
                'revision' => (int) $data['expected_revision'] + 1, 'settings' => json_encode($data['settings'], JSON_THROW_ON_ERROR),
                'updated_by' => $actor->id, 'updated_at' => now(),
            ]);

            return $this->show($organizationId, $actor);
        });
    }
}
