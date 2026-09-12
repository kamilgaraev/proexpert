<?php

declare(strict_types=1);

return [
    'viewer_converter_binary' => env('DESIGN_VIEWER_CONVERTER_BINARY', 'node'),
    'viewer_converter_timeout' => (float) env('DESIGN_VIEWER_CONVERTER_TIMEOUT', 6600),
    'viewer_converter_version' => (int) env('DESIGN_VIEWER_CONVERTER_VERSION', 5),
    'viewer_job_timeout' => (int) env('DESIGN_VIEWER_JOB_TIMEOUT', 6900),
    'viewer_stale_processing_seconds' => (int) env('DESIGN_VIEWER_STALE_PROCESSING_SECONDS', 7500),
    'ifc_elements_page_size' => (int) env('DESIGN_IFC_ELEMENTS_PAGE_SIZE', 100),
];
