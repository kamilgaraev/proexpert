<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class DiagnosticsController extends Controller
{
    public function checkTables(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        $tables = [
            'contracts',
            'completed_works',
            'completed_work_materials',
            'projects',
            'materials',
        ];
        
        $tableStatus = [];
        foreach ($tables as $table) {
            $exists = DB::getSchemaBuilder()->hasTable($table);
            $tableStatus[$table] = [
                'exists' => $exists,
                'count' => $exists ? DB::table($table)->count() : 0,
            ];
            
            if ($exists && $table === 'contracts' && $organizationId) {
                $tableStatus[$table]['org_count'] = DB::table($table)
                    ->where('organization_id', $organizationId)
                    ->count();
            }
        }
        
        return response()->json([
            'success' => true,
            'organization_id' => $organizationId,
            'user_id' => $user?->id,
            'tables' => $tableStatus,
            'db_connection' => config('database.default'),
        ]);
    }
}

