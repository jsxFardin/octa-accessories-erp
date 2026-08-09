<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\States\ArtworkVersionStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * Gate 1 — the artwork approval gate (01-domain-model §4).
 *
 * The database makes double approval impossible; these tests prove the application layer
 * agrees, and that a job card cannot reach `released` against anything else.
 */
beforeEach(function (): void {
    $this->actingAs(App\Models\User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
});

it('cannot approve two artwork versions for the same artwork', function (): void {
    $approved = ArtworkVersion::query()->where('status', ArtworkVersion::APPROVED)->firstOrFail();

    $challenger = ArtworkVersion::query()->create([
        'artwork_id' => $approved->artwork_id,
        'version_no' => 3,
        'status' => ArtworkVersion::DRAFT,
        'file_path' => 'artwork/demo/v3.ai',
        'checksum_sha256' => hash('sha256', 'v3'),
    ]);

    $states = app(ArtworkVersionStateMachine::class);
    $states->transition($challenger, ArtworkVersion::SUBMITTED);
    $states->transition($challenger, ArtworkVersion::APPROVED, ['customer_ref' => 'Email 2026-08-09']);

    // Approving supersedes the incumbent in the same transaction (A2), so there is still
    // exactly one approved row — never two, and never zero.
    expect(ArtworkVersion::query()->where('artwork_id', $approved->artwork_id)->where('status', ArtworkVersion::APPROVED)->count())
        ->toBe(1)
        ->and($approved->refresh()->status)->toBe(ArtworkVersion::SUPERSEDED)
        ->and($challenger->refresh()->status)->toBe(ArtworkVersion::APPROVED);
});

it('refuses to approve a version without the customer reference that evidences it', function (): void {
    $artworkId = ArtworkVersion::query()->value('artwork_id');

    $version = ArtworkVersion::query()->create([
        'artwork_id' => $artworkId,
        'version_no' => 4,
        'status' => ArtworkVersion::SUBMITTED,
        'file_path' => 'artwork/demo/v4.ai',
        'checksum_sha256' => hash('sha256', 'v4'),
    ]);

    expect(fn () => app(ArtworkVersionStateMachine::class)->transition($version, ArtworkVersion::APPROVED))
        ->toThrow(TransitionDenied::class, 'customer reference');
});

it('refuses to submit a version whose file has no checksum', function (): void {
    $artworkId = ArtworkVersion::query()->value('artwork_id');

    $version = ArtworkVersion::query()->create([
        'artwork_id' => $artworkId,
        'version_no' => 5,
        'status' => ArtworkVersion::DRAFT,
        'file_path' => 'artwork/demo/v5.ai',
    ]);

    // A3 — the checksum is what later proves the approved file is the file that was made.
    expect(fn () => app(ArtworkVersionStateMachine::class)->transition($version, ArtworkVersion::SUBMITTED))
        ->toThrow(TransitionDenied::class, 'checksummed');
});

it('cannot release a job card without an approved artwork version', function (): void {
    $jobCard = JobCard::query()->firstOrFail();

    // Supersede the version the card is welded to: the FK still resolves, but the gate must
    // now refuse, because "approved once" is not "approved".
    ArtworkVersion::query()
        ->where('id', $jobCard->artwork_version_id)
        ->update(['status' => ArtworkVersion::SUPERSEDED]);

    expect(fn () => app(JobCardStateMachine::class)->transition($jobCard->refresh(), JobCard::RELEASED))
        ->toThrow(TransitionDenied::class, 'Approved artwork version');
});

it('binds a job card to an approved version through a not-null foreign key', function (): void {
    $jobCard = JobCard::query()->firstOrFail();

    expect($jobCard->artwork_version_id)->not->toBeNull()
        ->and($jobCard->artworkVersion->status)->toBe(ArtworkVersion::APPROVED);

    // There is no code path that nulls it: the column itself refuses.
    expect(fn () => DB::table('job_cards')->where('id', $jobCard->id)->update(['artwork_version_id' => null]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('names every failed condition when a release is blocked', function (): void {
    $jobCard = JobCard::query()->firstOrFail();

    ArtworkVersion::query()->where('id', $jobCard->artwork_version_id)->update(['status' => ArtworkVersion::REJECTED]);
    DB::table('boms')->where('id', $jobCard->bom_id)->update(['status' => 'superseded']);

    // A supervisor blocked at 2 a.m. needs to know which of the four conditions failed.
    expect(fn () => app(JobCardStateMachine::class)->transition($jobCard->refresh(), JobCard::RELEASED))
        ->toThrow(function (TransitionDenied $e): void {
            expect($e->getMessage())
                ->toContain('Approved artwork version')
                ->toContain('Active BOM');
        });
});

it('records every transition in the audit log with its old and new status', function (): void {
    $artworkId = ArtworkVersion::query()->value('artwork_id');

    $version = ArtworkVersion::query()->create([
        'artwork_id' => $artworkId,
        'version_no' => 6,
        'status' => ArtworkVersion::DRAFT,
        'file_path' => 'artwork/demo/v6.ai',
        'checksum_sha256' => hash('sha256', 'v6'),
    ]);

    app(ArtworkVersionStateMachine::class)->transition($version, ArtworkVersion::SUBMITTED);

    $entry = DB::table('audit_logs')
        ->where('auditable_type', ArtworkVersion::class)
        ->where('auditable_id', $version->id)
        ->where('event', 'status_changed')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and(json_decode($entry->old_values, true))->toBe(['status' => ArtworkVersion::DRAFT])
        ->and(json_decode($entry->new_values, true)['status'])->toBe(ArtworkVersion::SUBMITTED);
});

it('treats a transition to the current status as a no-op, not an error', function (): void {
    $version = ArtworkVersion::query()->where('status', ArtworkVersion::APPROVED)->firstOrFail();
    $before = DB::table('audit_logs')->count();

    // Idempotence protects the shop floor from double-submits on flaky wifi (05-workflows §13).
    app(ArtworkVersionStateMachine::class)->transition($version, ArtworkVersion::APPROVED);

    expect(DB::table('audit_logs')->count())->toBe($before);
});
