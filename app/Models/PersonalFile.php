<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PersonalFile extends Model
{
    use HasFactory;

    protected $table = 'personal_files';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'organization_id',
        'user_id',
        'storage_key',
        'directory',
        'original_name',
        'mime_type',
        'sha256',
        'size',
        'is_folder',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'user_id' => 'integer',
        'size' => 'integer',
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
