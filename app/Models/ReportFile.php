<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class ReportFile extends Model
{
    use HasFactory;

    protected $table = 'report_files';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'path',
        'type',
        'filename',
        'name',
        'size',
        'expires_at',
        'user_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected static function booted()
    {
        parent::booted();

        static::creating(function (self $model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::ulid();
            }
        });
    }
} 