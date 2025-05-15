<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Thumbnail Settings for Site Requests
    |--------------------------------------------------------------------------
    |
    | Define the configurations for thumbnails that should be automatically
    | generated for images uploaded via Site Requests.
    |
    | Each configuration is an array with the following keys:
    |   'name_suffix' (string): Suffix for the thumbnail filename (e.g., '_thumb').
    |   'width' (int): Target width in pixels.
    |   'height' (int): Target height in pixels.
    |   'method' (string, optional): Intervention Image method ('fit', 'crop', 'resize'). Default: 'fit'.
    |   'quality' (int, optional): Image quality for JPEG/WEBP (1-100). Default: 90.
    |   'output_path_prefix' (string, optional): Subdirectory for thumbnails. Default: '_thumbs/'.
    |   'disk' (string, optional): Disk to save thumbnail. Default: same as original.
    |
    */
    'site_request_thumbnails' => [
        [
            'name_suffix' => '_thumb',
            'width' => 150,
            'height' => 150,
            'method' => 'crop', // Используем crop для маленьких квадратных превью
            'quality' => 85,
        ],
        [
            'name_suffix' => '_medium',
            'width' => 600,
            'height' => 600,
            'method' => 'fit', // Используем fit, чтобы изображение полностью поместилось
            'quality' => 90,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default disk for Site Request attachments
    |--------------------------------------------------------------------------
    */
    'default_site_request_disk' => env('FILESYSTEM_SITE_REQUEST_DISK', 'public'),

]; 