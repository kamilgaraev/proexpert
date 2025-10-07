<?php

namespace App\BusinessModules\Features\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Organization;
use App\Models\User;

class NotificationTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'type',
        'channel',
        'name',
        'subject',
        'content',
        'variables',
        'locale',
        'is_default',
        'is_active',
        'version',
        'parent_template_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'version' => 'integer',
    ];

    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
        'version' => 1,
        'locale' => 'ru',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parentTemplate(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'parent_template_id');
    }

    public function childTemplates(): HasMany
    {
        return $this->hasMany(NotificationTemplate::class, 'parent_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeForOrganization($query, ?int $organizationId)
    {
        if ($organizationId) {
            return $query->where('organization_id', $organizationId);
        }
        return $query->whereNull('organization_id');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeByLocale($query, string $locale)
    {
        return $query->where('locale', $locale);
    }

    public function createNewVersion(): self
    {
        $newVersion = $this->replicate();
        $newVersion->version = $this->version + 1;
        $newVersion->parent_template_id = $this->id;
        $newVersion->save();
        
        return $newVersion;
    }
}

