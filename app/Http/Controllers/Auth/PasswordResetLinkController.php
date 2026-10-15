<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * NFR-19 — self-service password reset.
 *
 * Until now a locked-out user's only path was an administrator retyping a password for them
 * and reading it out, which is how passwords end up shared. The link goes to the address on
 * the account and nowhere else, and the answer is the same whether or not that address exists,
 * so the form cannot be used to list accounts.
 */
class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::broker()->sendResetLink($request->only('email'));

        return back()->with('success', 'If that address has an account, a reset link is on its way. It is valid for an hour.');
    }
}
