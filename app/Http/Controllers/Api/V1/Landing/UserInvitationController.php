<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\User\InvitationService;
use Illuminate\Support\Facades\Auth;
use App\Models\UserInvitation;

class UserInvitationController extends Controller
{
    protected InvitationService $invitationService;

    public function __construct(InvitationService $invitationService)
    {
        $this->invitationService = $invitationService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();

        // Определяем организацию, по которой выводим приглашения
        $organizationId = $user->current_organization_id ?? optional($user->organizations()->first())->id;

        if (!$organizationId) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $invitations = \App\Models\UserInvitation::where('organization_id', $organizationId)
            ->latest('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $invitations,
        ]);
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
        $data = $request->validate([
            'email' => 'required|email',
            'name' => 'required|string|max:255',
            'roles' => 'array',
            'roles.*' => 'string',
        ]);

        $creator = Auth::user();
        $invitation = $this->invitationService->invite(
            $data['email'],
            $data['name'],
            $creator,
            $data['roles'] ?? []
        );

        return response()->json(['success'=>true,'data'=>$invitation]);
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

    public function resend(Request $request, int $invitationId)
    {
        $invitation = UserInvitation::findOrFail($invitationId);
        $this->invitationService->resend($invitation);
        return response()->json(['success'=>true]);
    }
}
