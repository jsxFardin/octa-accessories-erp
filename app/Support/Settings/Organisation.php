<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * Who the system belongs to and how it presents dates, times and numbers.
 *
 * Kept apart from the business coefficients in `Settings` because these have a different
 * audience: a purchase manager changes an approval band, but only an administrator changes
 * the company name or the timezone, and getting the timezone wrong silently shifts every
 * timestamp on every screen (NFR-49).
 *
 * Defaults are Bangladesh-first because the factory is: Asia/Dhaka, `d M Y`, 24-hour clock,
 * Saturday-first week — the Bangladeshi working week runs Sunday to Thursday, so a calendar
 * that starts on Monday puts the weekend in the middle.
 */
class Organisation
{
    public const DEFAULTS = [
        'org_name' => 'Maheen Label',
        'org_legal_name' => 'Maheen Label Industries Ltd.',
        'org_short_name' => 'Octa ERP',
        'org_logo_path' => null,
        'org_icon_path' => null,
        'org_address' => '',
        'org_phone' => '',
        'org_email' => '',
        'org_website' => '',
        'org_tax_id' => '',
        'timezone' => 'Asia/Dhaka',
        'date_format' => 'd M Y',
        'time_format' => 'HH:mm',
        'week_start' => 'saturday',
        'number_locale' => 'en-GB',
        'default_locale' => 'en',
    ];

    /** Formats offered by the settings screen, with a rendered sample so the choice is obvious. */
    public const DATE_FORMATS = ['d M Y', 'd/m/Y', 'Y-m-d', 'M d, Y', 'd.m.Y'];

    public const TIME_FORMATS = ['HH:mm', 'hh:mm a'];

    public const WEEK_STARTS = ['saturday', 'sunday', 'monday'];

    public const NUMBER_LOCALES = ['en-GB', 'en-US', 'en-IN', 'bn-BD'];

    public function __construct(private readonly Settings $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $values = [];

        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = $this->settings->get($key, $default) ?? $default;
        }

        return $values;
    }

    public function get(string $key): mixed
    {
        return $this->settings->get($key, self::DEFAULTS[$key] ?? null) ?? (self::DEFAULTS[$key] ?? null);
    }

    public function timezone(): string
    {
        $timezone = (string) $this->get('timezone');

        // A bad timezone would throw on every date cast in the application; fall back loudly
        // in the logs rather than taking the whole system down.
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            report(new \RuntimeException("Unknown organisation timezone [{$timezone}]; using the default."));

            return self::DEFAULTS['timezone'];
        }

        return $timezone;
    }

    /**
     * What the front end needs to render branding and format values consistently.
     *
     * @return array<string, mixed>
     */
    public function forFrontend(): array
    {
        $values = $this->all();

        return [
            'name' => $values['org_name'],
            'legal_name' => $values['org_legal_name'],
            'short_name' => $values['org_short_name'],
            'logo_url' => $this->assetUrl($values['org_logo_path']),
            'icon_url' => $this->assetUrl($values['org_icon_path']),
            'address' => $values['org_address'],
            'phone' => $values['org_phone'],
            'email' => $values['org_email'],
            'website' => $values['org_website'],
            'tax_id' => $values['org_tax_id'],
            'timezone' => $this->timezone(),
            'date_format' => $values['date_format'],
            'time_format' => $values['time_format'],
            'week_start' => $values['week_start'],
            'number_locale' => $values['number_locale'],
        ];
    }

    private function assetUrl(?string $path): ?string
    {
        return $path === null || $path === '' ? null : '/storage/'.ltrim($path, '/');
    }
}
