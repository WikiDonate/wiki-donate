<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SanitizeHtmlTest extends TestCase
{
    public function test_strips_event_handlers(): void
    {
        $this->assertSame('<p>hi</p>', sanitizeHtml('<p onclick="evil()">hi</p>'));
    }

    public function test_strips_svg_and_iframe_vectors(): void
    {
        $this->assertSame('', sanitizeHtml('<svg><animate onbegin="alert(1)"/></svg>'));
        $this->assertSame('', sanitizeHtml('<iframe src="https://evil.test"></iframe>'));
        $this->assertSame('', sanitizeHtml('<img src="x" onerror="alert(1)">'));
    }

    public function test_neutralizes_javascript_urls(): void
    {
        $this->assertSame('<a>x</a>', sanitizeHtml('<a href="javascript:alert(1)">x</a>'));
    }

    public function test_unwraps_nested_disallowed_tags(): void
    {
        $this->assertSame('unwrap me', sanitizeHtml('<div><span onclick="e()">unwrap me</span></div>'));
    }

    public function test_preserves_safe_markup(): void
    {
        $this->assertSame(
            '<p>keep <strong>bold</strong> and <a href="https://ok.test">link</a></p>',
            sanitizeHtml('<p>keep <strong>bold</strong> and <a href="https://ok.test">link</a></p>')
        );
    }
}
