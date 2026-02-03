<?php

namespace App\BusinessModules\Features\NormativeReferences\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class NormativeResource extends Model
{
    use HasFactory;

    protected $table = 'normative_resources';

    protected $fillable = [
        'code',
        'name',
        'unit',
        'price',
        'type',
        'source',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'price' => 'decimal:2',
    ];

    // Константы типов для удобства
    public const TYPE_MATERIAL = 'material';
    public const TYPE_EQUIPMENT = 'equipment';
    public const TYPE_MACHINE = 'machine';
    public const TYPE_WORK = 'work';
}
