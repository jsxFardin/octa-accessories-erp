<?php

declare(strict_types=1);

namespace App\Support\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The coefficients of the business rules (08-architecture §8).
 *
 * What belongs here: overhead percentages, tolerances, cut gaps, approval bands. What does
 * not: the formulas themselves. A rule that may change without a deploy is a setting; a rule
 * that must not change quietly is code with a test.
 */
class SettingController extends Controller
{
    public function __construct(private readonly Settings $settings) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Settings', [
            // `organisation` is excluded on purpose: it has a dedicated screen, and two places
            // editing the same key is how a timezone ends up half-changed.
            'groups' => collect($this->settings->grouped())->except('organisation')->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string', 'max:150'],
            'settings.*.value' => ['present'],
        ]);

        foreach ($data['settings'] as $setting) {
            $this->settings->set($setting['key'], $setting['value']);
        }

        // Every read goes through the cached accessor, so the flush is what makes the change
        // visible — and it happens here rather than being left to a TTL.
        $this->settings->flush();

        return back()->with('success', count($data['settings']).' setting(s) updated.');
    }
}
