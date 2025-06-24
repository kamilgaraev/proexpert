<?php

namespace App\Http\Controllers;

use App\Services\Monitoring\PrometheusService;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    protected PrometheusService $prometheus;

    public function __construct(PrometheusService $prometheus)
    {
        $this->prometheus = $prometheus;
    }

    public function metrics(): Response
    {
        try {
            $metrics = $this->prometheus->renderMetrics();
            
            return response($metrics, 200, [
                'Content-Type' => 'text/plain; charset=utf-8'
            ]);
        } catch (\Exception $e) {
            // Отладочная информация
            return response("Error: " . $e->getMessage() . "\n" . $e->getTraceAsString(), 500, [
                'Content-Type' => 'text/plain; charset=utf-8'
            ]);
        }
    }

    public function test(): Response
    {
        return response("# Test metrics endpoint\ntest_metric 1\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8'
        ]);
    }
} 