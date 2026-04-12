<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrigadeDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'brigade_id',
        'title',
        'document_type',
        'file_path',
        'file_name',
        'verification_status',
        'verified_at',
        'verification_notes',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function brigade(): BelongsTo
    {
        return $this->belongsTo(BrigadeProfile::class, 'brigade_id');
    }
}
