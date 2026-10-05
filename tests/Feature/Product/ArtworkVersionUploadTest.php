<?php

declare(strict_types=1);

use App\Modules\Product\Models\Artwork;
use App\Modules\Product\Models\ArtworkVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * `file_format` is guarded by the artwork_versions_format_chk check constraint, so an
 * extension the constraint does not list aborts the insert and the upload screen 500s.
 * The upload rules are wider than the column: they accept both spellings of a JPEG, and
 * they check the extension guessed from the file's *contents*, never the one in the
 * filename the browser sent.
 */
beforeEach(function (): void {
    Storage::fake('local');

    $this->actingAs(App\Models\User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());

    $this->artwork = Artwork::query()->firstOrFail();
});

function uploadedFormat(int $artworkId): ?string
{
    return ArtworkVersion::query()
        ->where('artwork_id', $artworkId)
        ->latest('id')
        ->value('file_format');
}

it('stores a .jpeg upload as jpg', function (): void {
    $this->post(route('artworks.versions.store', $this->artwork), [
        'file' => UploadedFile::fake()->image('logo.jpeg'),
    ])->assertRedirect();

    expect(uploadedFormat($this->artwork->id))->toBe('jpg');
});

it('stores no format at all when the filename extension is not one the column holds', function (): void {
    $image = UploadedFile::fake()->image('logo.png');

    $this->post(route('artworks.versions.store', $this->artwork), [
        // A real PNG under a filename the browser is free to make up: the mimes rule passes
        // it on its contents, and the client extension reaches the column unexamined.
        'file' => new UploadedFile($image->getRealPath(), 'logo.bar', 'image/png', null, true),
    ])->assertRedirect();

    expect(uploadedFormat($this->artwork->id))->toBeNull();
});

it('keeps a listed extension as it is', function (): void {
    $this->post(route('artworks.versions.store', $this->artwork), [
        'file' => UploadedFile::fake()->image('logo.png'),
    ])->assertRedirect();

    expect(uploadedFormat($this->artwork->id))->toBe('png');
});

/*
 * UX audit H-14. A wrong upload could not be taken back, and a file that was too large or the
 * wrong kind was refused in the stock validation sentence.
 */
it('withdraws a draft that was never sent, and gives its number back', function (): void {
    $next = $this->artwork->nextVersionNo();

    $this->post("/artworks/{$this->artwork->id}/versions", ['file' => UploadedFile::fake()->image('wrong.png')])
        ->assertSessionHasNoErrors();

    $version = ArtworkVersion::query()->where('artwork_id', $this->artwork->id)->latest('id')->firstOrFail();
    expect($version->version_no)->toBe($next)->and($version->canBeWithdrawn())->toBeTrue();
    Storage::disk('local')->assertExists($version->file_path);

    $this->delete("/artwork-versions/{$version->id}")->assertSessionHas('success');

    expect(ArtworkVersion::query()->whereKey($version->id)->exists())->toBeFalse()
        ->and($this->artwork->nextVersionNo())->toBe($next);
    Storage::disk('local')->assertMissing($version->file_path);
});

it('does not withdraw a version the customer has been sent, or one that is not the newest', function (): void {
    $upload = function (): ArtworkVersion {
        $this->post("/artworks/{$this->artwork->id}/versions", ['file' => UploadedFile::fake()->image('a.png')]);

        return ArtworkVersion::query()->where('artwork_id', $this->artwork->id)->latest('id')->firstOrFail();
    };

    $sent = $upload();
    $sent->forceFill(['submitted_at' => now()])->save();

    $this->delete("/artwork-versions/{$sent->id}")->assertSessionHas('error');
    expect(ArtworkVersion::query()->whereKey($sent->id)->exists())->toBeTrue();

    // An older draft, with a newer version above it: removing it would leave a hole in the numbers.
    $sent->forceFill(['submitted_at' => null])->save();
    $newer = $upload();

    $this->delete("/artwork-versions/{$sent->id}")->assertSessionHas('error');
    expect(ArtworkVersion::query()->whereKey($sent->id)->exists())->toBeTrue()
        ->and($newer->canBeWithdrawn())->toBeTrue();
});

it('says in plain words why an upload was refused', function (): void {
    $this->post("/artworks/{$this->artwork->id}/versions", ['file' => UploadedFile::fake()->create('huge.pdf', 60 * 1024, 'application/pdf')])
        ->assertSessionHasErrors(['file' => 'This file is larger than 50 MB. Export a smaller copy and upload that.']);

    $this->post("/artworks/{$this->artwork->id}/versions", ['file' => UploadedFile::fake()->create('notes.docx', 10)])
        ->assertSessionHasErrors(['file' => 'This kind of file cannot be uploaded. Use AI, EPS, PDF, CDR, PSD, PNG, JPG or SVG.']);
});
