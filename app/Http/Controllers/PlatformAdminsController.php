<?php

namespace App\Http\Controllers;

use App\Models\PlatformAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Lets an existing platform operator add teammates — no public signup, and
 * no distinction between operators (any of them can onboard tenants and add
 * more operators). Reachable only through the "platform_admin" guard, same
 * as TenantsController.
 */
class PlatformAdminsController extends Controller
{
    public function index()
    {
        try {
            return \SuccessData('Platform admins retrieved', PlatformAdmin::orderBy('id')->get());
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('platform_admins', 'email')],
            'password' => 'required|string|min:8',
        ]);

        try {
            $admin = PlatformAdmin::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'is_active' => true,
            ]);

            return \SuccessData('Platform admin created', $admin);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
