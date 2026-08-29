<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

use function trans_message;

final readonly class ActiveOrganizationUser implements ValidationRule
{
    public function __construct(private int $organizationId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || $this->organizationId < 1) {
            $fail(trans_message('user.selected_employee_invalid'));

            return;
        }

        $exists = DB::table('users')
            ->where('users.id', (int) $value)
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->whereExists(function ($query) use ($value): void {
                $query->selectRaw('1')
                    ->from('organization_user')
                    ->whereColumn('organization_user.user_id', 'users.id')
                    ->where('organization_user.user_id', (int) $value)
                    ->where('organization_user.organization_id', $this->organizationId)
                    ->where('organization_user.is_active', true);
            })
            ->exists();

        if (! $exists) {
            $fail(trans_message('user.selected_employee_invalid'));
        }
    }
}
