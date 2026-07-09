<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->validate([
            'photo' => 'nullable|image|max:4096',
            'remove_photo' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $user->fill($request->validated());

        if ($request->boolean('remove_photo')) {
            if ($user->avatar_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar_path);
            }
            $user->avatar_path = null;
        } elseif ($request->hasFile('photo')) {
            if ($user->avatar_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar_path);
            }
            $path = $request->file('photo')->store('avatars', 'public');
            $user->avatar_path = $path;
        }

        $user->save();

        return back();
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
