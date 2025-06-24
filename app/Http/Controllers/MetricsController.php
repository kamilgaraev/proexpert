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
        $metrics = $this->prometheus->renderMetrics();
        
        return response($metrics, 200, [
            'Content-Type' => 'text/plain; charset=utf-8'
        ]);
    }
} 