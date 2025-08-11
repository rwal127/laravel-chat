<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;

class AvatarController extends Controller
{
    /**
     * Update the authenticated user's avatar.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'avatar' => ['required', 'file', 'image', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif'],
        ]);

        $user = $request->user();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->avatar = $path;
        $user->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Remove the authenticated user's avatar.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->avatar = null;
            $user->save();
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }
}
