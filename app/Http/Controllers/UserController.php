<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        $users = User::orderBy('full_name', 'asc')->get(['id', 'username', 'password', 'full_name as name', 'role', 'email', 'phone', 'is_active', 'last_login', 'created_at', 'updated_at']);

        return Inertia::render('Settings/Users', [
            'users' => $users,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:50|unique:users,username',
            'password' => 'required|string|min:6',
            'role' => 'required|in:admin,front_desk,cashier,housekeeping',
            'is_active' => 'required|boolean',
        ]);

        $admin = $request->user();
        if ($admin->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        $user = User::create([
            'full_name' => $request->name,
            'username' => $request->username,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'is_active' => $request->is_active,
        ]);

        BookingService::auditLog(
            $admin->id,
            'USER_CREATE',
            'users',
            $user->id,
            null,
            $user->username,
            "Created new user account: {$user->username} with role {$user->role}."
        );

        return back()->with('success', "User '{$user->full_name}' created successfully.");
    }

    public function update(User $user, Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'required|in:admin,front_desk,cashier,housekeeping',
            'is_active' => 'required|boolean',
            'password' => 'nullable|string|min:6',
        ]);

        $admin = $request->user();
        if ($admin->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        $oldVal = $user->toArray();

        $user->full_name = $request->name;
        $user->role = $request->role;
        $user->is_active = $request->is_active;

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();
        $newVal = $user->toArray();

        BookingService::auditLog(
            $admin->id,
            'USER_UPDATE',
            'users',
            $user->id,
            $oldVal,
            $newVal,
            "Updated user details for username: {$user->username}."
        );

        return back()->with('success', "User '{$user->full_name}' updated successfully.");
    }
}
