<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

final class OneCBase extends Model
{
    protected $table = 'one_c_bases';

    protected $hidden = [
        'endpoint_url_encrypted',
    ];

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'environment',
        'connector',
        'endpoint_url_encrypted',
        'metadata_path',
        'endpoint_fingerprint',
        'protocol_version',
        'connector_version',
        'status',
        'connection_status',
        'last_connection_check_at',
        'last_connection_check_code',
        'last_successful_exchange_at',
        'timeout_seconds',
        'connect_timeout_seconds',
        'retry_policy',
        'supported_scopes',
        'warning_codes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'endpoint_url_encrypted' => 'encrypted',
        'last_connection_check_at' => 'datetime',
        'last_successful_exchange_at' => 'datetime',
        'timeout_seconds' => 'integer',
        'connect_timeout_seconds' => 'integer',
        'retry_policy' => 'array',
        'supported_scopes' => 'array',
        'warning_codes' => 'array',
    ];

    public function profiles(): HasMany
    {
        return $this->hasMany(OneCIntegrationProfile::class, 'one_c_base_id');
    }

    public function endpointUrl(): ?string
    {
        try {
            $value = $this->endpoint_url_encrypted;
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function safeEndpointDisplay(): ?string
    {
        $endpoint = $this->endpointUrl();

        if ($endpoint === null) {
            return null;
        }

        $parts = parse_url($endpoint);

        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':'.(string) $parts['port'] : '';
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';

        return $scheme.$host.$port.$path;
    }
}
