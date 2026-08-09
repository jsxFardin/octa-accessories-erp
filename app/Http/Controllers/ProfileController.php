<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A user's own account.
 *
 * No permission guards these routes: changing your own password is not something an
 * administrator should have to grant, and an install where nobody can change their password
 * is an install where everybody keeps the one they were seeded with.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'roles' => $user->roles->map->only(['name', 'label']),
                'last_login_at' => $user->last_login_at,
                // Seeded accounts share one password. Say so on the screen rather than
                // hoping someone reads the README.
                'using_seed_password' => Hash::check('password', $user->password),
            ],
        ]);
    }

    /**
     * The language switch in the topbar. Its own route because changing your display
     * language should not require typing your password.
     */
    public function updateLocale(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'in:en,bn']]);

        $request->user()->forceFill(['locale' => $data['locale']])->save();

        return back();
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()->uncompromised()],
            'locale' => ['nullable', 'in:en,bn'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $user->forceFill([
            'password' => $data['password'],
            'locale' => $data['locale'] ?? $user->locale,
        ])->save();

        return back()->with('success', 'Password updated.');
    }
}
