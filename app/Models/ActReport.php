<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class ActReport extends Model
{
    protected $fillable = [
        'organization_id',
        'performance_act_id',
        'report_number',
        'title',
        'format',
        'file_path',
        's3_key',
        'file_size',
        'metadata',
        'expires_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function performanceAct(): BelongsTo
    {
        return $this->belongsTo(ContractPerformanceAct::class, 'performance_act_id');
    }

    public function getDownloadUrl(): ?string
    {
        if ($this->s3_key) {
            $s3Config = config('filesystems.disks.s3');
            $baseUrl = $s3Config['url'] ?? "https://{$s3Config['bucket']}.s3.{$s3Config['region']}.amazonaws.com";
            return rtrim($baseUrl, '/') . '/' . ltrim($this->s3_key, '/');
        }
        
        return null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at < now();
    }

    public function getFileSizeFormatted(): string
    {
        if (!$this->file_size) {
            return '0 B';
        }

        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function deleteFile(): bool
    {
        if ($this->s3_key && Storage::disk('s3')->exists($this->s3_key)) {
            return Storage::disk('s3')->delete($this->s3_key);
        }
        
        return true;
    }

    public static function generateReportNumber(): string
    {
        $date = now()->format('Ymd');
        $count = self::whereDate('created_at', today())->count() + 1;
        
        return "ACT-{$date}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->report_number)) {
                $model->report_number = self::generateReportNumber();
            }
            
            if (empty($model->expires_at)) {
                $model->expires_at = now()->addMonth();
            }
        });

        static::deleting(function ($model) {
            $model->deleteFile();
        });
    }
}
