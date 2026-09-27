<?php

if (! function_exists('parseHtmlSection')) {
    function parseHtmlSection($htmlText)
    {
        if (empty($htmlText)) {
            return [];
        }

        // Wrap the HTML in a container div to ensure proper DOM structure
        $wrappedHtml = '<div>'.$htmlText.'</div>';

        $dom = new DOMDocument;
        @$dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        // Get all <h2> elements
        $headings = $dom->getElementsByTagName('h2');
        $data = [];

        foreach ($headings as $index => $heading) {
            // Extract the title with HTML formatting preserved
            $title = trim($dom->saveHTML($heading));
            // Get the next sibling of the <h2> tag
            $nextElement = $heading->nextSibling;
            $content = '';

            while ($nextElement) {
                if ($nextElement->nodeName === 'h2') {
                    // Stop when the next <h2> is encountered
                    break;
                }

                if ($nextElement->nodeType === XML_ELEMENT_NODE) {
                    // Append the content if it's an HTML element
                    $content .= $dom->saveHTML($nextElement);
                }

                $nextElement = $nextElement->nextSibling;
            }

            // Clean up any extra <br> tags or empty content
            $content = preg_replace('/<p>\s*<br>\s*<\/p>/', '', trim($content));

            // Add the title and content to the result
            $data[] = [
                'title' => $title,
                'content' => $content,
            ];
        }

        return $data;
    }
}

if (! function_exists('sanitizeHtml')) {
    /**
     * Allowlist HTML sanitizer (no external dependency).
     *
     * The old regex version was bypassable (<svg/onload>, entity-encoded
     * javascript: URLs, <iframe>/<object>). This parses the DOM and:
     *  - drops dangerous elements entirely (script, style, iframe, object,
     *    embed, form, inputs, media with remote sources),
     *  - unwraps harmless containers (div, span, section, ...),
     *  - keeps formatting/links, scrubbing every attribute except
     *    http(s) hrefs on <a>.
     */
    function sanitizeHtml(string $html): string
    {
        $allowed = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'u', 'a', 'br', 'blockquote', 'hr'];
        $dropEntirely = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'base', 'img', 'video', 'audio', 'source', 'track', 'canvas', 'svg', 'math', 'noscript', 'template'];

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $cleanNode = function (DOMNode $node) use (&$cleanNode, $allowed, $dropEntirely) {
            // Worklist (not a snapshot): nodes inserted by unwrapping are
            // cleaned too, so nested disallowed tags can't slip through.
            $queue = iterator_to_array($node->childNodes);
            while ($queue !== []) {
                $child = array_shift($queue);
                if ($child->parentNode !== $node) {
                    continue;
                }
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    /** @var DOMElement $child */
                    $tag = strtolower($child->tagName);

                    if (in_array($tag, $dropEntirely, true)) {
                        $node->removeChild($child);

                        continue;
                    }

                    if (! in_array($tag, $allowed, true)) {
                        // Unwrap: keep children, drop the tag itself.
                        $inserted = iterator_to_array($child->childNodes);
                        foreach ($inserted as $grandchild) {
                            $node->insertBefore($grandchild, $child);
                        }
                        $node->removeChild($child);
                        // Re-queue inserted nodes right after current position.
                        $queue = array_merge($inserted, $queue);

                        continue;
                    }

                    // Allowed element: strip every attribute except safe href.
                    $href = null;
                    if ($tag === 'a' && $child->hasAttribute('href')) {
                        $raw = trim($child->getAttribute('href'));
                        if (preg_match('#^https?://#i', $raw) && filter_var($raw, FILTER_VALIDATE_URL)) {
                            $href = $raw;
                        }
                    }
                    while ($child->attributes->length > 0) {
                        $child->removeAttribute($child->attributes->item(0)->name);
                    }
                    if ($href !== null) {
                        $child->setAttribute('href', $href);
                    }

                    $cleanNode($child);
                } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                    $node->removeChild($child);
                }
            }
        };

        $wrapper = $dom->getElementsByTagName('div')->item(0);
        if ($wrapper) {
            $cleanNode($wrapper);
        }

        $out = '';
        if ($wrapper) {
            foreach ($wrapper->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }
        }

        return $out;
    }
}
