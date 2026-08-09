<?php

declare(strict_types=1);

namespace App\Support\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Settings\Organisation;
use App\Support\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organisation profile: who the system belongs to, and how it renders dates, times and
 * numbers. One screen, because these settings are read together and changed together.
 */
class OrganisationController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Organisation $organisation,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Organisation', [
            'organisation' => [
                ...$this->organisation->all(),
                'logo_url' => $this->organisation->forFrontend()['logo_url'],
                'icon_url' => $this->organisation->forFrontend()['icon_url'],
            ],
            'options' => [
                'timezones' => $this->timezones(),
                'date_formats' => array_map(
                    // The sample is the whole point: nobody reads `d/m/Y` and pictures it.
                    fn (string $format): array => [
                        'value' => $format,
                        'label' => now($this->organisation->timezone())->format($format),
                    ],
                    Organisation::DATE_FORMATS,
                ),
                'time_formats' => [
                    ['value' => 'HH:mm', 'label' => '24-hour  —  '.now($this->organisation->timezone())->format('H:i')],
                    ['value' => 'hh:mm a', 'label' => '12-hour  —  '.now($this->organisation->timezone())->format('h:i a')],
                ],
                'week_starts' => array_map(
                    fn (string $day): array => ['value' => $day, 'label' => ucfirst($day)],
                    Organisation::WEEK_STARTS,
                ),
                'number_locales' => array_map(
                    fn (string $locale): array => [
                        'value' => $locale,
                        'label' => $locale.'  —  '.number_format(1234567.89, 2),
                    ],
                    Organisation::NUMBER_LOCALES,
                ),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'org_name' => ['required', 'string', 'max:120'],
            'org_legal_name' => ['nullable', 'string', 'max:180'],
            'org_short_name' => ['required', 'string', 'max:60'],
            'org_address' => ['nullable', 'string', 'max:400'],
            'org_phone' => ['nullable', 'string', 'max:60'],
            'org_email' => ['nullable', 'email', 'max:180'],
            'org_website' => ['nullable', 'string', 'max:180'],
            'org_tax_id' => ['nullable', 'string', 'max:60'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'date_format' => ['required', Rule::in(Organisation::DATE_FORMATS)],
            'time_format' => ['required', Rule::in(Organisation::TIME_FORMATS)],
            'week_start' => ['required', Rule::in(Organisation::WEEK_STARTS)],
            'number_locale' => ['required', Rule::in(Organisation::NUMBER_LOCALES)],
        ]);

        foreach ($data as $key => $value) {
            $this->settings->set($key, $value, 'organisation');
        }

        return back()->with('success', 'Organisation profile updated.');
    }

    /**
     * Branding upload. Kept off the main save so a failed image never loses the form, and
     * restricted to raster/SVG marks — this is a logo slot, not a file store.
     */
    public function updateBranding(Request $request): RedirectResponse
    {
        $request->validate([
            'kind' => ['required', Rule::in(['logo', 'icon'])],
            'file' => ['required', 'file', 'mimetypes:image/png,image/jpeg,image/svg+xml,image/webp', 'max:1024'],
        ]);

        $kind = $request->string('kind')->toString();
        $key = "org_{$kind}_path";

        $previous = $this->settings->get($key);
        $path = $request->file('file')->store('branding', 'public');

        $this->settings->set($key, $path, 'organisation');

        // One mark per slot; the replaced file is no longer reachable from anywhere.
        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return back()->with('success', ucfirst($kind).' updated.');
    }

    public function destroyBranding(Request $request): RedirectResponse
    {
        $request->validate(['kind' => ['required', Rule::in(['logo', 'icon'])]]);

        $key = 'org_'.$request->string('kind')->toString().'_path';
        $previous = $this->settings->get($key);

        if (is_string($previous) && $previous !== '') {
            Storage::disk('public')->delete($previous);
        }

        $this->settings->set($key, null, 'organisation');

        return back()->with('success', 'Removed.');
    }

    /**
     * Every zone would be a 400-row dropdown of noise. These are the ones a Bangladeshi
     * manufacturer and its buyers actually sit in, plus UTC.
     *
     * @return list<array{value: string, label: string}>
     */
    private function timezones(): array
    {
        $zones = [
            'Asia/Dhaka', 'Asia/Kolkata', 'Asia/Karachi', 'Asia/Shanghai', 'Asia/Hong_Kong',
            'Asia/Singapore', 'Asia/Dubai', 'Europe/London', 'Europe/Amsterdam', 'Europe/Berlin',
            'Europe/Stockholm', 'Europe/Istanbul', 'America/New_York', 'America/Los_Angeles', 'UTC',
        ];

        return array_map(
            fn (string $zone): array => [
                'value' => $zone,
                'label' => str_replace('_', ' ', $zone).'  ('.now($zone)->format('H:i').')',
            ],
            $zones,
        );
    }
}
