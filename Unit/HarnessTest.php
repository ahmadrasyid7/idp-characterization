<?php

declare(strict_types=1);

use Characterization\Config;
use Characterization\Normalizer;
use PHPUnit\Framework\TestCase;

final class HarnessTest extends TestCase
{
    /** @dataProvider allowedTargets */
    public function testAllowsOnlyLocalTargets(string $url): void
    {
        self::assertSame(rtrim($url, '/'), Config::validateTarget($url));
    }

    public static function allowedTargets(): array
    {
        return [
            ['http://localhost:8080/'],
            ['http://127.0.0.1'],
            ['http://idp-local.rschooltoday.test/'],
            ['https://idp-local.rschooltoday.test'],
        ];
    }

    /** @dataProvider rejectedTargets */
    public function testRejectsRemoteAndAmbiguousTargets(string $url): void
    {
        $this->expectException(RuntimeException::class);
        Config::validateTarget($url);
    }

    public static function rejectedTargets(): array
    {
        return [
            ['https://idp.rschooltoday.com'],
            ['https://stag-idp.rschooltoday.com'],
            ['http://idp-local.rschooltoday.test.evil.test'],
            ['ftp://localhost'],
            ['http://user@localhost'],
            ['http://localhost/path'],
            ['http://localhost?url=remote'],
            ['http://localhost#remote'],
            ['not-a-url'],
            ['http://another-app.test'],
        ];
    }

    public function testNormalizationMasksVolatileValuesButKeepsBehavior(): void
    {
        $html = '<html><body><input name="csrf_token_idp" value="volatile-secret">'
            .'<script>volatile-js</script><style>.volatile{}</style>'
            .'<p>ZZTEST_1234abcd 2026-11-03 12:00:00 550e8400-e29b-41d4-a716-446655440000</p>'
            .'<p>http://localhost Access Denied!</p></body></html>';
        self::assertSame('{FIXTURE} {TIMESTAMP} {GUID} {BASE_URL} Access Denied!',
            Normalizer::text($html, 'http://localhost', 'ZZTEST_1234abcd'));
    }
}
