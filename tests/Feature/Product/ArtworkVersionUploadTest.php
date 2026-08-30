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
