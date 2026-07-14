<?php

$marker = getenv('MOST_BENCHMARK_TREE_MARKER');
if (is_string($marker) && $marker !== '') {
    $code = <<<'PHP'
$marker = getenv('MOST_BENCHMARK_TREE_MARKER');
usleep(5_000_000);
if (is_string($marker) && $marker !== '') {
    file_put_contents($marker, 'child-survived');
}
PHP;
    $descriptors = [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
        2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
    ];
    proc_open([PHP_BINARY, '-r', $code], $descriptors, $pipes);
}

usleep(10_000_000);
