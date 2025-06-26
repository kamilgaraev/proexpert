<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\OrganizationGroup;
use App\Models\Organization;
use Symfony\Component\HttpFoundation\Response;

class DetectHoldingSubdomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $domain = config('app.domain', 'prohelper.pro');
        
        if (!str_ends_with($host, $domain)) {
            return $next($request);
        }
        
        $subdomain = str_replace('.' . $domain, '', $host);
        
        // Исключаем служебные поддомены
        $excludedSubdomains = ['www', 'lk', 'api', 'admin', 'mail', 'ftp'];
        if ($subdomain === $domain || in_array($subdomain, $excludedSubdomains)) {
            return $next($request);
        }
        
        $cacheKey = "holding_subdomain_{$subdomain}";
        $holding = Cache::remember($cacheKey, 300, function () use ($subdomain) {
            return OrganizationGroup::where('slug', $subdomain)
                ->where('status', 'active')
                ->with('parentOrganization')
                ->first();
        });
        
        if (!$holding) {
            abort(404, 'Холдинг не найден');
        }
        
        $request->attributes->set('holding', $holding);
        $request->attributes->set('holding_context', true);
        $request->attributes->set('current_organization_id', $holding->parent_organization_id);
        $request->attributes->set('current_organization', $holding->parentOrganization);
        
        app()->instance('current_holding', $holding);
        
        return $next($request);
    }
} 