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

        $organizationId = $user->current_organization_id;

        if (!$organizationId) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $invitations = \App\Models\UserInvitation::where('organization_id', $organizationId)
            ->with(['invitedBy', 'acceptedBy', 'organization'])
            ->latest('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => \App\Http\Resources\Api\V1\Landing\UserInvitationResource::collection($invitations),
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
    public function show(int $invitationId)
    {
        $user = Auth::user();
        $organizationId = $user->current_organization_id;

        if (!$organizationId) {
            return response()->json(['success' => false, 'message' => 'Не определён контекст организации'], 400);
        }

        $invitation = UserInvitation::where('id', $invitationId)
            ->where('organization_id', $organizationId)
            ->with(['invitedBy', 'acceptedBy', 'organization'])
            ->first();

        if (!$invitation) {
            return response()->json(['success' => false, 'message' => 'Приглашение не найдено'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new \App\Http\Resources\Api\V1\Landing\UserInvitationResource($invitation),
        ]);
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
    public function destroy(int $invitationId)
    {
        $user = Auth::user();
        $organizationId = $user->current_organization_id;

        if (!$organizationId) {
            return response()->json(['success' => false, 'message' => 'Не определён контекст организации'], 400);
        }

        $invitation = UserInvitation::where('id', $invitationId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$invitation) {
            return response()->json(['success' => false, 'message' => 'Приглашение не найдено'], 404);
        }

        $invitation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Приглашение удалено'
        ]);
    }

    public function resend(Request $request, int $invitationId)
    {
        $user = Auth::user();
        $organizationId = $user->current_organization_id;

        if (!$organizationId) {
            return response()->json(['success' => false, 'message' => 'Не определён контекст организации'], 400);
        }

        $invitation = UserInvitation::where('id', $invitationId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $this->invitationService->resend($invitation);

        return response()->json([
            'success' => true,
            'message' => 'Приглашение отправлено повторно'
        ]);
    }

    public function stats(Request $request)
    {
        $user = Auth::user();
        $organizationId = $user->current_organization_id;

        if (!$organizationId) {
            return response()->json(['success' => false, 'message' => 'Не определён контекст организации'], 400);
        }

        $stats = $this->invitationService->getInvitationStats($organizationId);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
