<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\LandingAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

use function trans_message;

class LandingAdminController extends Controller
{
    public function index(): JsonResponse
    {
        $admins = LandingAdmin::query()->paginate();

        return LandingResponse::success($admins, trans_message('landing.landing_admin.loaded'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:landing_admins,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['sometimes', 'string', 'max:50'],
        ]);

        $data['password'] = Hash::make($data['password']);
        if (!isset($data['role'])) {
            $data['role'] = 'admin';
        }

        $admin = LandingAdmin::create($data);

        return LandingResponse::success($admin, trans_message('landing.landing_admin.created'), 201);
    }

    public function show(LandingAdmin $landingAdmin): JsonResponse
    {
        return LandingResponse::success($landingAdmin, trans_message('landing.landing_admin.loaded'));
    }

    public function update(Request $request, LandingAdmin $landingAdmin): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('landing_admins')->ignore($landingAdmin->id)],
            'password' => ['sometimes', 'string', 'min:8'],
            'role' => ['sometimes', 'string', 'max:50'],
        ]);

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        if (!isset($data['role'])) {
            $data['role'] = 'admin';
        }

        $landingAdmin->update($data);

        return LandingResponse::success($landingAdmin, trans_message('landing.landing_admin.updated'));
    }

    public function destroy(LandingAdmin $landingAdmin): JsonResponse
    {
        $landingAdmin->delete();

        return LandingResponse::success(null, trans_message('landing.landing_admin.deleted'));
    }
}
