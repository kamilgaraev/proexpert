<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class PeopleRagSource extends ModelDomainRagSource
{
    public function sourceType(): string
    {
        return 'people';
    }

    public function entities(): array
    {
        return ['user' => ['model' => User::class, 'fields' => ['id','name','email']]];
    }

    protected function query(string $class, int $organizationId, ?int $projectId): Builder
    {
        return User::query()->where('users.is_active', true)->whereHas('organizations', static function (Builder $organizations) use ($organizationId): void {
            $organizations->where('organizations.id', $organizationId)->where('organization_user.is_active', true);
        });
    }
}
