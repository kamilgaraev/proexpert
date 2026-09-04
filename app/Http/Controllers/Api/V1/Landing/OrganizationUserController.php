<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\Api\V1\Landing\User\OrganizationTeamIndexRequest;
use App\Http\Responses\LandingResponse;
use App\Services\User\OrganizationTeamDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrganizationUserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(OrganizationTeamIndexRequest $request, OrganizationTeamDirectory $directory): JsonResponse
    {
        try {
            $input = $request->validated();
            $users = $directory->paginate(
                $request->user(),
                (int) $request->attributes->get('current_organization_id'),
                (string) ($input['search'] ?? ''),
                (int) ($input['page'] ?? 1),
                (int) ($input['per_page'] ?? 20),
            );

            return LandingResponse::paginated($users->items(), [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ]);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Organization team listing failed', [
                'user_id' => $request->user()?->getAuthIdentifier(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'exception' => $exception,
            ]);

            return LandingResponse::error(trans_message('landing_users.team_list_error'), 500);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
