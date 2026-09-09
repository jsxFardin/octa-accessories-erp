<?php

declare(strict_types=1);

namespace App\Support\Platform;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Strips the executable parts out of an uploaded SVG.
 *
 * The branding slot accepts `image/svg+xml`, and the file lands on the `public` disk where the
 * web server hands it back verbatim. Rendered through `<img src>` — which is how the print
 * layout uses it — an SVG cannot run script. Navigated to directly, `/storage/branding/x.svg`
 * is a document on the application's own origin, and a `<script>` inside it runs there with
 * access to whatever that origin holds.
 *
 * The upload needs `setting.update`, so this is not a route in from outside; it is the
 * difference between an administrator account and script running as any admin who opens the
 * file. Cheap to close, so it is closed.
 *
 * A vector logo is worth keeping — it is the one image in the system that gets printed at
 * arbitrary size — so this cleans the file rather than refusing the format.
 *
 * Deliberately a denylist over the known-executable surface rather than an element allowlist:
 * SVG's drawing vocabulary is large and a strict allowlist quietly mangles legitimate logos
 * (gradients, filters, clip paths, embedded fonts), which is how a "safe" sanitiser becomes one
 * nobody is allowed to use.
 */
class SvgSanitiser
{
    /** Elements that exist to execute, navigate or embed something else. */
    private const FORBIDDEN_ELEMENTS = [
        'script', 'foreignObject', 'iframe', 'embed', 'object', 'handler',
        'audio', 'video', 'animation', 'listener', 'set',
    ];

    /** Schemes an `href` may carry. Anything else — javascript:, data:text/html — is dropped. */
    private const SAFE_HREF = '/^(#|data:image\/(png|jpeg|gif|webp);base64,)/i';

    public function clean(string $svg): string
    {
        $document = new DOMDocument;

        // No network, no entity expansion: an uploaded file must not be able to make the
        // server fetch a URL or unfold a billion-laughs payload while parsing.
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // Unparseable is not sanitisable. Refusing beats storing something we did not read.
        if ($loaded === false || $document->documentElement === null) {
            return '';
        }

        $xpath = new DOMXPath($document);

        foreach (self::FORBIDDEN_ELEMENTS as $name) {
            // Case-insensitively, and regardless of namespace prefix: `<SCRIPT>` and
            // `<svg:script>` are the same element to a browser.
            $query = sprintf(
                '//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "%s"]',
                strtolower($name),
            );

            $this->removeAll($xpath->query($query));
        }

        $this->stripAttributes($document->documentElement);

        $cleaned = $document->saveXML();

        return $cleaned === false ? '' : $cleaned;
    }

    /** True when the bytes look like an SVG document at all. */
    public function looksLikeSvg(string $contents): bool
    {
        return stripos($contents, '<svg') !== false;
    }

    private function removeAll(mixed $nodes): void
    {
        if (! $nodes) {
            return;
        }

        // Materialised first: removing from a live DOMNodeList while iterating skips siblings.
        $doomed = [];

        foreach ($nodes as $node) {
            $doomed[] = $node;
        }

        foreach ($doomed as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function stripAttributes(DOMNode $node): void
    {
        if ($node instanceof DOMElement && $node->hasAttributes()) {
            /** @var list<DOMAttr> $attributes */
            $attributes = [];

            foreach ($node->attributes as $attribute) {
                $attributes[] = $attribute;
            }

            foreach ($attributes as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = $attribute->nodeValue ?? '';

                // Every event handler, whatever it is called. `onload`, `onclick`, and the
                // ones that have not been invented yet.
                if (str_starts_with($name, 'on')) {
                    $node->removeAttributeNode($attribute);

                    continue;
                }

                // A reference that leaves the document, or carries a script URL in place of
                // one. `#gradient-1` and an inline raster stay; `javascript:` does not.
                if (in_array($name, ['href', 'xlink:href'], true)
                    && preg_match(self::SAFE_HREF, trim($value)) !== 1) {
                    $node->removeAttributeNode($attribute);

                    continue;
                }

                // `style="background:url(javascript:…)"` and its relatives.
                if (stripos($value, 'javascript:') !== false) {
                    $node->removeAttributeNode($attribute);
                }
            }
        }

        foreach (iterator_to_array($node->childNodes ?? []) as $child) {
            $this->stripAttributes($child);
        }
    }
}
