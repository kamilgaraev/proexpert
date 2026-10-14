<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const PERMISSIONS = [
        'workforce.hr.private.read', 'workforce.hr.private.write', 'workforce.hr.documents.read', 'workforce.hr.documents.manage',
        'workforce.hr.recruitment.manage', 'workforce.hr.lifecycle.manage',
        'workforce.timekeeping.view', 'workforce.timekeeping.manage', 'workforce.timekeeping.verify', 'workforce.timekeeping.approve',
        'workforce.timekeeping.close', 'workforce.timekeeping.export',
        'workforce.self-service.view', 'workforce.self-service.request', 'workforce.team-queue.view', 'workforce.team-queue.manage',
        'workforce.payroll-source.view', 'workforce.payroll-source.correct', 'workforce.exports.view',
    ];
    public function up(): void { $this->update(true); }
    public function down(): void { $this->update(false); }
    private function update(bool $add): void
    {
        $module = DB::table('modules')->where('slug', 'workforce-management')->first(['id', 'permissions']);
        if ($module === null) { return; }
        $permissions = json_decode((string) ($module->permissions ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($permissions)) { throw new RuntimeException('Invalid workforce permissions'); }
        $updated = $add ? array_values(array_unique([...$permissions, ...self::PERMISSIONS]))
            : array_values(array_filter($permissions, fn (string $permission): bool => ! in_array($permission, self::PERMISSIONS, true)));
        DB::table('modules')->where('id', $module->id)->update(['permissions' => json_encode($updated, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
    }
};
