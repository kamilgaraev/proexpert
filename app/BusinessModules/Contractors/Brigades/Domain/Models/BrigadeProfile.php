<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades\Domain\Models;

use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrigadeProfile extends Model
{
    use HasFactory;

    protected $table = 'brigades';

    protected $fillable = [
        'organization_id',
        'owner_user_id',
        'name',
        'slug',
        'description',
        'team_size',
        'contact_person',
        'contact_phone',
        'contact_email',
        'availability_status',
        'verification_status',
        'regions',
        'rating',
        'completed_projects_count',
        'settings',
    ];

    protected $casts = [
        'team_size' => 'integer',
        'regions' => 'array',
        'rating' => 'decimal:2',
        'completed_projects_count' => 'integer',
        'settings' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $profile): void {
            if (!$profile->availability_status) {
                $profile->availability_status = BrigadeStatuses::AVAILABILITY_AVAILABLE;
            }

            if (!$profile->verification_status) {
                $profile->verification_status = BrigadeStatuses::PROFILE_DRAFT;
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(BrigadeMember::class, 'brigade_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BrigadeDocument::class, 'brigade_id');
    }

    public function specializations(): BelongsToMany
    {
        return $this->belongsToMany(BrigadeSpecialization::class, 'brigade_profile_specialization', 'brigade_id', 'specialization_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(BrigadeInvitation::class, 'brigade_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(BrigadeResponse::class, 'brigade_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(BrigadeProjectAssignment::class, 'brigade_id');
    }

    public function scopeApproved($query)
    {
        return $query->where('verification_status', BrigadeStatuses::PROFILE_APPROVED);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_user_id === $user->id;
    }
}
