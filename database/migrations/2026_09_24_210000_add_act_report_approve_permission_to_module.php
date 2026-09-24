<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MODULE_SLUG = 'act-reporting';

    private const PERMISSION = 'act_reports.approve';

    public function up(): void
    {
        $this->updatePermission(true);
    }

    public function down(): void
    {
        $this->updatePermission(false);
    }

    private function updatePermission(bool $add): void
    {
        $module = DB::table('modules')->where('slug', self::MODULE_SLUG)->first(['id', 'permissions']);
        if ($module === null) {
            return;
        }

        $permissions = json_decode((string) ($module->permissions ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($permissions)) {
            throw new RuntimeException('Invalid act-reporting permissions');
        }

        $updated = $add
            ? array_values(array_unique([...$permissions, self::PERMISSION]))
            : array_values(array_filter($permissions, static fn (string $permission): bool => $permission !== self::PERMISSION));

        if ($updated === $permissions) {
            return;
        }

        DB::table('modules')->where('id', $module->id)->update([
            'permissions' => json_encode($updated, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
