<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('project_organization as po')
            ->join('projects as p', 'p.id', '=', 'po.project_id')
            ->whereColumn('po.organization_id', 'p.organization_id')
            ->where(function ($query): void {
                $query
                    ->where('po.role_new', 'owner')
                    ->orWhere(function ($fallback): void {
                        $fallback
                            ->whereNull('po.role_new')
                            ->where('po.role', 'owner');
                    });
            })
            ->delete();
    }

    public function down(): void
    {
        throw new RuntimeException('Legacy owner participant rows cannot be restored automatically.');
    }
};
