<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LandingAdmin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class LandingAdminController extends Controller
{
    /**
     * Display a listing of the landing admins.
     */
    public function index()
    {
        $admins = LandingAdmin::query()->paginate();
        return response()->json($admins);
    }

    /**
     * Store a newly created landing admin.
     */
    public function store(Request $request)
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
        return response()->json($admin, 201);
    }

    /**
     * Display the specified landing admin.
     */
    public function show(LandingAdmin $landingAdmin)
    {
        return response()->json($landingAdmin);
    }

    /**
     * Update the specified landing admin.
     */
    public function update(Request $request, LandingAdmin $landingAdmin)
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
        return response()->json($landingAdmin);
    }

    /**
     * Remove the specified landing admin.
     */
    public function destroy(LandingAdmin $landingAdmin)
    {
        $landingAdmin->delete();
        return response()->json(['message' => 'Deleted'], 204);
    }
} 