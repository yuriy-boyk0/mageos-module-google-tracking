<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\GaCookieParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GaCookieParserTest extends TestCase
{
    private GaCookieParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GaCookieParser();
    }

    #[DataProvider('clientIds')]
    public function testParseClientId(?string $cookie, ?string $expected): void
    {
        $this->assertSame($expected, $this->parser->parseClientId($cookie));
    }

    public static function clientIds(): array
    {
        return [
            'standard' => ['GA1.1.1234567890.1700000000', '1234567890.1700000000'],
            'subdomain level 2' => ['GA1.2.987654321.1699999999', '987654321.1699999999'],
            'surrounding whitespace' => [' GA1.1.111.222 ', '111.222'],
            'missing' => [null, null],
            'empty' => ['', null],
            'garbage' => ['not-a-cookie', null],
            'too few parts' => ['GA1.1.123', null],
        ];
    }

    #[DataProvider('sessionIds')]
    public function testParseSessionId(?string $cookie, ?string $expected): void
    {
        $this->assertSame($expected, $this->parser->parseSessionId($cookie));
    }

    public static function sessionIds(): array
    {
        return [
            'GS1 format' => ['GS1.1.1700000123.4.1.1700000456.0.0.0', '1700000123'],
            'GS2 format' => ['GS2.1.s1700000123$o4$g1$t1700000456$j0$l0$h0', '1700000123'],
            'GS2 without trailing params' => ['GS2.1.s1700000123', '1700000123'],
            'missing' => [null, null],
            'garbage' => ['GS3.1.abc', null],
        ];
    }

    public function testSessionCookieNameStripsThePrefix(): void
    {
        $this->assertSame('_ga_ABC123XYZ9', $this->parser->sessionCookieName('G-ABC123XYZ9'));
    }
}
