<?php

declare(strict_types=1);

return [
    'viewer_converter_binary' => env('DESIGN_VIEWER_CONVERTER_BINARY', 'node'),
    'viewer_converter_timeout' => (float) env('DESIGN_VIEWER_CONVERTER_TIMEOUT', 7200),
    'viewer_converter_version' => (int) env('DESIGN_VIEWER_CONVERTER_VERSION', 3),
];
