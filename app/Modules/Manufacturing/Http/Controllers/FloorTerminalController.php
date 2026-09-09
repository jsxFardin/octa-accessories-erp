<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\Services\DeviceSession;
use App\Modules\Manufacturing\Services\DeviceSessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop-floor terminal. FloorLayout: large targets, high contrast, four buttons, Bangla by
 * default — because a machine operator is wearing gloves under a glare, not sitting at a desk
 * (08-architecture §4).
 *
 * Signing in is one act, not two. The badge screen used to trade a card and a PIN for an API
 * token in localStorage and nothing else, then send the operator to `/floor/queue` — a `web`
 * route behind `auth`. On a kiosk browser with no prior session that redirected straight to
 * the password login, so the terminal only ever worked for someone who had *already* signed
 * in with an email and a password and then scanned a badge on top. Badge-and-PIN now signs
 * the operator into the session as well, and the token it mints rides along for the offline
 * queue (07-api-contracts §7).
 */
class FloorTerminalController extends Controller
{
    /** Where the minted device token is parked so the terminal can hand it to the device API. */
    private const TOKEN_KEY = 'floor.device_token';

    public function __construct(private readonly DeviceSessionRegistry $sessions) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Floor/Login', [
            'machines' => DB::table('machines')->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            // A supervisor who is already signed in at a desk should not have to find a badge
            // they were never issued. Offered as a second door, never as the default one: the
            // badge fields stay on top because the operator is who this screen is for.
            'signedInAs' => $user?->can('operation.terminal') ? $user->name : null,
        ]);
    }

    /** Badge scan plus PIN, in exchange for a shift-length session (06-rbac §6). */
    public function signIn(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'card_no' => ['required', 'string', 'max:40'],
            'pin' => ['required', 'string', 'max:10'],
            'machine_code' => ['nullable', 'string', 'max:30'],
        ]);

        $session = $this->sessions->issue($data['card_no'], $data['pin'], $data['machine_code'] ?? null);

        if ($session === null) {
            return back()->with('error', 'ব্যাজ বা পিন মেলেনি · Badge or PIN not recognised.');
        }

        return $this->start($request, $session);
    }

    /** The second door: a desk user who has already proved who they are with a password. */
    public function continueAsUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'machine_code' => ['nullable', 'string', 'max:30'],
        ]);

        $session = $this->sessions->issueForUser($request->user(), $data['machine_code'] ?? null);

        if ($session === null) {
            return back()->with('error', 'Your account has no active employee record, so the terminal has no factory unit to show a queue for. Ask an administrator to link one under Configuration → Users.');
        }

        return $this->start($request, $session);
    }

    /**
     * End of shift. The next operator at this kiosk must scan their own badge — otherwise they
     * would inherit the previous one's session and book their output under someone else's name.
     */
    public function signOut(Request $request): RedirectResponse
    {
        $token = $request->session()->get(self::TOKEN_KEY);

        if (is_string($token)) {
            $this->sessions->revoke($token);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('floor.index');
    }

    public function queue(Request $request): Response
    {
        return Inertia::render('Floor/Queue', [
            'machineCode' => $request->query('machine'),
            // Handed to the page rather than fetched by it: the token is minted at sign-in and
            // the queue seeds localStorage from here, so a kiosk that lost its storage — a
            // cleared cache, a fresh profile — recovers on the next page load instead of
            // bouncing the operator back to the badge screen.
            'deviceToken' => $request->session()->get(self::TOKEN_KEY),
            'operator' => $request->user()?->name,
        ]);
    }

    public function operation(JobCardOperation $operation): Response
    {
        $operation->load(['jobCard.product', 'jobCard.artworkVersion.artwork', 'machine']);

        return Inertia::render('Floor/Operation', [
            'operation' => [
                ...$operation->only([
                    'id', 'sequence_no', 'code', 'name', 'planned_qty', 'input_qty',
                    'good_qty', 'waste_qty', 'status', 'started_at',
                ]),
                'machine' => $operation->machine?->only(['id', 'code', 'name']),
                'remaining_allowance' => $operation->remainingOutputAllowance(),
                'job_card' => [
                    'id' => $operation->jobCard?->id,
                    'number' => $operation->jobCard?->number,
                    'product_code' => $operation->jobCard?->product?->code,
                    'colourway' => $operation->jobCard?->colourway,
                    'planned_qty' => $operation->jobCard?->planned_qty,
                    // Gate 1, visible on the floor: the operator can see which artwork
                    // version this run is bound to.
                    'artwork' => $operation->jobCard?->artworkVersion?->artwork?->code
                        .' v'.$operation->jobCard?->artworkVersion?->version_no,
                ],
            ],
            'downtimeReasons' => DB::table('downtime_reasons')->orderBy('name')
                ->get(['id', 'code', 'name', 'category']),
            'shifts' => DB::table('shifts')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Session fixation is the reason the id is regenerated before the token is parked: the
     * token has to survive the regeneration, so it is written after it, not before.
     */
    private function start(Request $request, DeviceSession $session): RedirectResponse
    {
        $user = $session->user();

        if ($user === null) {
            return back()->with('error', 'That badge is not linked to a user account.');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(self::TOKEN_KEY, $session->token);

        // `can:operation.terminal` guards the queue. Refusing here, with a reason, beats
        // signing someone in and dropping them on a 403 they cannot read from a machine.
        if (! $user->can('operation.terminal')) {
            $this->signOut($request);

            return redirect()->route('floor.index')
                ->with('error', 'এই ব্যাজে টার্মিনাল চালানোর অনুমতি নেই · This badge may not run the terminal.');
        }

        return redirect()->route('floor.queue', array_filter(['machine' => $session->machineCode]));
    }
}
