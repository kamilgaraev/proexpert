<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Contract;
use App\Services\Contract\ContractBuilderMutationGuard;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class GuardLegacyContractSpecification
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $organizationId = (int) $request->attributes->get('current_organization_id', $actor?->current_organization_id);
        if ($actor === null || (int) $actor->current_organization_id !== $organizationId) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($request, $next, $organizationId): Response {
            $contract = Contract::whereKey((int) $request->route('contract'))->where('organization_id', $organizationId)->lockForUpdate()->firstOrFail();
            app(ContractBuilderMutationGuard::class)->assertLegacy($contract);
            $response = $next($request);
            if ($response->getStatusCode() >= 400) {
                throw new HttpResponseException($response);
            }

            return $response;
        });
    }
}
