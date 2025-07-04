<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'invited_by_user_id',
        'user_id',
        'email',
        'name',
        'role_slugs',
        'token',
        'expires_at',
        'plain_password',
        'status',
        'sent_at',
        'metadata',
    ];

    protected $casts = [
        'role_slugs' => 'array',
        'expires_at' => 'datetime',
        'sent_at'    => 'datetime',
        'metadata'   => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
