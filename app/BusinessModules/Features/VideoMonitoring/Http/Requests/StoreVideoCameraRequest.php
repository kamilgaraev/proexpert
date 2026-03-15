<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\VideoMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVideoCameraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'zone' => ['nullable', 'string', 'max:255'],
            'source_type' => ['required', 'string', 'in:rtsp,nvr,cloud,agent'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'playback_url' => ['nullable', 'string', 'max:2048'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'stream_path' => ['nullable', 'string', 'max:2048'],
            'transport_protocol' => ['nullable', 'string', 'in:tcp,udp,http,https'],
            'is_enabled' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
        ];
    }
}
