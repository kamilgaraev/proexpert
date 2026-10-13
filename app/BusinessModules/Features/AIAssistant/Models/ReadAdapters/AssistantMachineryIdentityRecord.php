<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

use Illuminate\Database\Eloquent\Model;

final class AssistantMachineryIdentityRecord extends Model
{
    protected $table = 'machinery';
    protected $guarded = ['*'];
}
