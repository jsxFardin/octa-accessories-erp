<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\User;
use App\Support\Auth\Totp;
use App\Support\Settings\Organisation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
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
                'two_factor' => $this->twoFactorState($user),
            ],
        ]);
    }

    /**
     * NFR-19 — what the two-step card shows: on, waiting for the confirming code (the secret
     * is shown only in that state, never once confirmed), or off; and whether the role
     * demands it.
     *
     * @return array{enabled: bool, required: bool, days_left: ?int, pending_secret: ?string, provisioning_uri: ?string}
     */
    private function twoFactorState(User $user): array
    {
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null
            ? Crypt::decryptString($user->two_factor_secret)
            : null;

        return [
            'enabled' => $user->two_factor_confirmed_at !== null,
            'required' => RequireTwoFactor::requiredFor($user),
            'days_left' => RequireTwoFactor::daysLeftFor($user),
            'pending_secret' => $pending,
            'provisioning_uri' => $pending === null ? null : Totp::provisioningUri(
                $pending,
                $user->email,
                (string) (app(Organisation::class)->forFrontend()['short_name'] ?? 'Trimflow'),
            ),
        ];
    }

    /** Mint a secret for the authenticator to enrol from. Nothing changes at sign-in until it is confirmed. */
    public function twoFactorSetup(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_confirmed_at !== null) {
            return back()->with('error', 'Two-step sign-in is already on.');
        }

        $user->forceFill(['two_factor_secret' => Crypt::encryptString(Totp::generateSecret())])->save();

        return back()->with('success', 'Add the key to your authenticator app, then enter the code it shows.');
    }

    /** The code proves the app holds the secret; only then does sign-in start asking for one. */
    public function twoFactorConfirm(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        if ($user->two_factor_secret === null || $user->two_factor_confirmed_at !== null) {
            return back()->with('error', 'Start the set-up first.');
        }

        if (! Totp::verify(Crypt::decryptString($user->two_factor_secret), $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Codes change every thirty seconds; try the current one.']);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return back()->with('success', 'Two-step sign-in is on. You will be asked for a code the next time you sign in.');
    }

    /** Turning it off needs the password, and is not offered to the roles that require it. */
    public function twoFactorDisable(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'That is not your password.']);
        }

        if (RequireTwoFactor::requiredFor($user)) {
            return back()->with('error', 'Your role requires two-step sign-in; it cannot be turned off.');
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

        return back()->with('success', 'Two-step sign-in is off.');
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

        // NFR-26 — every other browser signed in as this account is signed out, including
        // ones kept alive by a remember-me cookie. This one stays.
        Auth::logoutOtherDevices($data['password']);

        return back()->with('success', 'Password updated. Any other browser signed in as you has been signed out.');
    }
}
