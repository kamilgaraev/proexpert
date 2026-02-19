<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Http\Responses\AdminResponse;

class PingController extends Controller
{
    public function ping(): JsonResponse
    {
        return AdminResponse::success([
            'message' => 'pong',
            'timestamp' => now()->toIso8601String(),
            'service' => 'backend'
        ]);
    }
}
