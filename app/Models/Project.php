<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'address',
        'description',
        'start_date',
        'end_date',
        'status',
        'additional_info',
        'is_archived',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'additional_info' => 'array',
        'is_archived' => 'boolean',
    ];

    /**
     * Получить организацию, которой принадлежит проект.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить пользователей, назначенных на проект.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'project_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Получить приемки материалов по проекту.
     */
    public function materialReceipts(): HasMany
    {
        return $this->hasMany(MaterialReceipt::class);
    }

    /**
     * Получить списания материалов по проекту.
     */
    public function materialWriteOffs(): HasMany
    {
        return $this->hasMany(MaterialWriteOff::class);
    }

    /**
     * Получить выполненные работы по проекту.
     */
    public function completedWorks(): HasMany
    {
        return $this->hasMany(CompletedWork::class);
    }

    /**
     * Получить остатки материалов по проекту.
     */
    public function materialBalances(): HasMany
    {
        return $this->hasMany(MaterialBalance::class);
    }

    /**
     * Получить файлы, прикрепленные к проекту.
     */
    public function files()
    {
        return $this->morphMany(File::class, 'fileable');
    }
}
