<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

if (! function_exists('getDetailsByUUID')) {
    /**
     * Get details of a record by UUID and table name.
     */
    function getDetailsByUUID(string $uuid, string $table): ?array
    {
        // Ensure table exists
        if (! Schema::hasTable($table)) {
            return null;
        }

        // Fetch the record
        $record = DB::table($table)->where('uuid', $uuid)->first();

        // Return as array or null if not found
        return $record ? (array) $record : null;
    }
}

if (! function_exists('parseHtmlSection')) {
    function parseHtmlSection($htmlText)
    {
        if (empty($htmlText)) {
            return [];
        }

        $dom = new DOMDocument;
        @$dom->loadHTML($htmlText, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        // Get all <h2> elements
        $headings = $dom->getElementsByTagName('h2');
        $data = [];

        foreach ($headings as $heading) {
            // Extract the title text, including any child elements like <u>
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

if (! function_exists('verifyRecaptcha')) {
    function verifyRecaptcha($token)
    {
        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => env('RECAPTCHA_SECRET'),
            'response' => $token,
        ]);

        return $response->json()['success'] ?? false;
    }
}
