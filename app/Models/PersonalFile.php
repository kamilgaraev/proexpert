<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class PersonalFile extends Model
{
    use HasFactory;

    protected $table = 'personal_files';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'path',
        'filename',
        'size',
        'is_folder',
    ];

    protected $casts = [
        'is_folder' => 'boolean',
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