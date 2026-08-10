<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Settings\Organisation;
use App\Support\Settings\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();
});

// --- Organisation profile ----------------------------------------------------------------

it('saves the organisation profile and serves it to every page', function (): void {
    $this->actingAs($this->admin)->put('/admin/organisation', [
        'org_name' => 'Maheen Label',
        'org_legal_name' => 'Maheen Label Industries Ltd.',
        'org_short_name' => 'Maheen ERP',
        'timezone' => 'Asia/Kolkata',
        'date_format' => 'Y-m-d',
        'time_format' => 'hh:mm a',
        'week_start' => 'monday',
        'number_locale' => 'en-US',
    ])->assertRedirect();

    app(Settings::class)->flush();

    $shared = app(Organisation::class)->forFrontend();

    expect($shared['short_name'])->toBe('Maheen ERP')
        ->and($shared['timezone'])->toBe('Asia/Kolkata')
        ->and($shared['date_format'])->toBe('Y-m-d')
        ->and($shared['number_locale'])->toBe('en-US');
});

it('refuses a timezone the server does not know', function (): void {
    $this->actingAs($this->admin)->put('/admin/organisation', [
        'org_name' => 'Maheen Label',
        'org_short_name' => 'Octa ERP',
        'timezone' => 'Mars/Olympus_Mons',
        'date_format' => 'd M Y',
        'time_format' => 'HH:mm',
        'week_start' => 'saturday',
        'number_locale' => 'en-GB',
    ])->assertSessionHasErrors('timezone');
});

it('falls back rather than throwing when a stored timezone is unusable', function (): void {
    // Settings are data, and data can be edited outside the form. Every date cast in the
    // application would otherwise throw on the next request.
    app(Settings::class)->set('timezone', 'Nowhere/Nothing', 'organisation');

    expect(app(Organisation::class)->timezone())->toBe('Asia/Dhaka');
});

it('stores a branding upload and exposes it as a URL', function (): void {
    Storage::fake('public');

    $this->actingAs($this->admin)->post('/admin/organisation/branding', [
        'kind' => 'icon',
        'file' => UploadedFile::fake()->image('mark.png', 64, 64),
    ])->assertRedirect();

    app(Settings::class)->flush();

    $path = app(Settings::class)->get('org_icon_path');

    expect($path)->toBeString();
    Storage::disk('public')->assertExists($path);

    expect(app(Organisation::class)->forFrontend()['icon_url'])->toBe('/storage/'.$path);
});

it('rejects a branding upload that is not an image', function (): void {
    Storage::fake('public');

    $this->actingAs($this->admin)->post('/admin/organisation/branding', [
        'kind' => 'logo',
        'file' => UploadedFile::fake()->create('payload.svg.php', 8, 'application/x-php'),
    ])->assertSessionHasErrors('file');
});

it('keeps the organisation group out of the business rules tab', function (): void {
    // Two places editing the same key is how a timezone ends up half-changed: the profile is
    // edited on its own tab, never in the raw rules list.
    $groups = app(Settings::class)->grouped();

    expect($groups)->toHaveKey('organisation');

    $this->actingAs($this->admin)->get('/admin/settings?tab=rules')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('groups.organisation'));
});

it('serves organisation and business rules as tabs of one screen', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Settings')
            ->where('tab', 'organisation')
            ->has('organisation')
            ->has('options.timezones')
            ->has('groups'));

    $this->actingAs($this->admin)->get('/admin/settings?tab=rules')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tab', 'rules'));
});

it('sends the old organisation URL to its tab', function (): void {
    // The screen moved; the links people already have should not break.
    $this->actingAs($this->admin)->get('/admin/organisation')
        ->assertRedirect('/admin/settings?tab=organisation');
});

it('does not lose a setting\'s group or description when its value is saved', function (): void {
    // The regression this pins: `updateOrInsert` wrote the default group and a null
    // description on every ordinary save, so one press of Save on the settings screen
    // flattened all 23 settings into "General" and erased every explanation.
    $before = DB::table('settings')->where('key', 'overhead_pct')->first();

    $this->actingAs($this->admin)->put('/admin/settings', [
        'settings' => [['key' => 'overhead_pct', 'value' => 13.5]],
    ])->assertRedirect();

    $after = DB::table('settings')->where('key', 'overhead_pct')->first();

    expect((float) json_decode($after->value, true))->toBe(13.5)
        ->and($after->group_name)->toBe($before->group_name)
        ->and($after->description)->toBe($before->description);
});

it('labels every setting it renders', function (): void {
    // A screen of raw keys — `cut_gap_hot_cut`, `po_approval_band_manager` — is a screen
    // nobody outside the build team can safely edit.
    $catalogue = new App\Support\Settings\SettingCatalogue;

    $uncatalogued = collect(DB::table('settings')->pluck('key'))
        ->reject(fn (string $key): bool => array_key_exists($key, App\Support\Settings\SettingCatalogue::ENTRIES))
        ->all();

    expect($uncatalogued)->toBe([]);

    foreach (app(Settings::class)->grouped() as $group) {
        foreach ($group['settings'] as $setting) {
            expect($setting['label'])->not->toBe($setting['key'])
                ->and($setting['hint'])->not->toBeEmpty();
        }
    }

    expect($catalogue->groupLabel('costing'))->toBe('Costing');
});

// --- Command palette search --------------------------------------------------------------

it('finds a document by its number', function (): void {
    $order = DB::table('sales_orders')->whereNotNull('number')->first();

    $response = $this->actingAs($this->admin)
        ->getJson('/search?q='.substr((string) $order->number, 0, 8));

    $response->assertOk();

    $titles = collect($response->json('groups'))->flatMap(fn (array $g): array => array_column($g['items'], 'title'));

    expect($titles)->toContain($order->number);
});

it('says nothing at all for a one-character term', function (): void {
    // Otherwise every keystroke of a document number scans eleven tables.
    $this->actingAs($this->admin)->getJson('/search?q=S')
        ->assertOk()
        ->assertJson(['groups' => []]);
});

it('never searches a source the user may not read', function (): void {
    // An operator holds no commercial permissions; the palette must not become a side channel
    // into the order book.
    $operator = User::query()->where('email', 'operator1@maheenlabel.test')->first()
        ?? User::query()->whereHas('roles', fn ($q) => $q->where('name', 'operator'))->firstOrFail();

    $labels = collect($this->actingAs($operator)->getJson('/search?q=SO-')->json('groups'))
        ->pluck('label');

    expect($labels)->not->toContain('Sales orders')
        ->and($labels)->not->toContain('Quotations');
});
