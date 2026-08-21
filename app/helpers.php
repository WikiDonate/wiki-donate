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
    function sanitizeHtml(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/', '', $html);
        $html = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/', 'href="#"', $html);

        return $html;
    }
}
