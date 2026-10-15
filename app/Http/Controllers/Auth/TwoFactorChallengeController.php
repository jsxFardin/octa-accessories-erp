<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\Totp;
use App\Support\Http\LandingPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * NFR-19 — the second step of signing in for an account with two-step sign-in on.
 *
 * The password step does not sign the user in; it parks who they are in the session and
 * sends them here. Only a code from their authenticator completes the login, so a stolen
 * password alone opens nothing. The parked id lives ten minutes.
 */
class TwoFactorChallengeController extends Controller
{
    public const SESSION_KEY = 'two_factor.pending';

    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get(self::SESSION_KEY) === null) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < time()) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('login')->with('error', 'That sign-in attempt has expired. Start again.');
        }

        /** @var User|null $user */
        $user = User::query()->find($pending['user_id']);

        if ($user === null || ! $user->is_active || $user->two_factor_secret === null) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('login');
        }

        if (! Totp::verify(Crypt::decryptString($user->two_factor_secret), $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Codes change every thirty seconds; try the current one.']);
        }

        $request->session()->forget(self::SESSION_KEY);
        Auth::login($user, (bool) ($pending['remember'] ?? false));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(LandingPage::for($user));
    }
}
