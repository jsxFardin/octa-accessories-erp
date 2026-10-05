<?php

declare(strict_types=1);

use App\Support\Text\Plain;

/*
 * A guard for the copy rules (UX audit M-03, M-04, M-06).
 *
 * Every sentence the server hands to a person — a flash, a validation message, a refusal — is
 * read out of the source and checked, as the screen will show it (rule numbers are taken off on
 * the way to the page by Plain::message), for the words the UI kit bans.
 */
function serverSentences(): array
{
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/app'));

    // A string literal handed straight to something that shows it to a person.
    $callers = '(?:->with\(\s*[\'"](?:error|success|warning)[\'"]\s*,|TransitionDenied::guard\(\s*[\'"][^\'"]*[\'"]\s*,|refuse(?:Unless|If)?\([^,\'"]*,|\$fail\(|OperationRefused::because\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*,|=>)\s*';
    $literal = '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")';

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            if (! preg_match_all('/'.$callers.$literal.'/', $line, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $text = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

                // A sentence, not a key, a column or a rule reference: it has a space and a lower-case word.
                if (str_word_count($text) < 4 || ! preg_match('/[a-z]{3,} [a-z]{2,}/', $text)) {
                    continue;
                }

                $found[] = [str_replace(dirname(__DIR__, 3).'/', '', $file->getPathname()).':'.($number + 1), $text];
            }
        }
    }

    return $found;
}

it('keeps banned wording out of the sentences the server shows people', function (): void {
    $banned = [
        'internal term' => '/\b(snapshot\w*|ledger|observer|state machine|immutable|posting path|query scope)\b/i',
        'GRN used alone' => '/\bGRNs?\b(?!\))/',
        'challan used alone' => '/(?<!\()\bchallans?\b(?!\))/i',
        'rule code' => '/\b(BR|QL|P\d)-\d+\b|\b[JIS]\d+:/',
        'raw status or permission key' => '/\[[a-z]+(_[a-z]+)*(\.[a-z_]+)?\]/',
    ];

    $offenders = [];

    foreach (serverSentences() as [$where, $text]) {
        // Variables are not words: `{$challan->number}` prints a number, not "challan".
        $shown = (string) Plain::message(preg_replace('/\{\$[^}]*\}|\$\w+(->\w+)*/', '…', $text));

        foreach ($banned as $what => $pattern) {
            if (preg_match($pattern, $shown, $hit)) {
                $offenders[] = "{$where}: {$what} “{$hit[0]}” in: ".mb_substr($shown, 0, 110);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('finds the sentences it is meant to check', function (): void {
    // If the extraction ever stops matching, the guard above would pass on nothing.
    expect(count(serverSentences()))->toBeGreaterThan(300);
});
