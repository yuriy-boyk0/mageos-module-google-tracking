<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\DataLayer;

use MageOS\GoogleTagManager\Service\DataLayer\GtmScriptUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GtmScriptUrlTest extends TestCase
{
    public static function validUrls(): array
    {
        return [
            'host' => ['https://gtm.example.com', 'https://gtm.example.com', 'gtm.example.com'],
            'trailing slash' => ['https://gtm.example.com/', 'https://gtm.example.com', 'gtm.example.com'],
            'port' => ['https://gtm.example.com:8443', 'https://gtm.example.com:8443', 'gtm.example.com'],
            'upper case and spaces' => ['  HTTPS://GTM.Example.COM ', 'https://gtm.example.com', 'gtm.example.com'],
            'single label' => ['https://localhost', 'https://localhost', 'localhost'],
        ];
    }

    #[DataProvider('validUrls')]
    public function testAcceptsAnHttpsOrigin(string $url, string $origin, string $host): void
    {
        $validator = new GtmScriptUrl();
        self::assertSame($origin, $validator->normalize($url));
        self::assertSame($host, $validator->host($url));
    }

    public static function invalidUrls(): array
    {
        return [
            'empty' => [''],
            'plain http' => ['http://gtm.example.com'],
            'path' => ['https://gtm.example.com/metrics'],
            'gtm.js path' => ['https://gtm.example.com/gtm.js'],
            'query' => ['https://gtm.example.com?id=1'],
            'fragment' => ['https://gtm.example.com#x'],
            'credentials' => ['https://user:pass@gtm.example.com'],
            'no scheme' => ['gtm.example.com'],
            'protocol relative' => ['//gtm.example.com'],
            'port zero' => ['https://gtm.example.com:0'],
            'port too high' => ['https://gtm.example.com:70000'],
            'space in host' => ['https://gtm example.com'],
            'label starts with dash' => ['https://-gtm.example.com'],
            'empty label' => ['https://gtm..example.com'],
            'quote' => ['https://gtm.example.com"'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function testRejectsAnythingElse(string $url): void
    {
        $validator = new GtmScriptUrl();
        self::assertNull($validator->normalize($url));
        self::assertSame('', $validator->host($url));
    }
}
