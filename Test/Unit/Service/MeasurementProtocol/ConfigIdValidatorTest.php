<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConfigIdValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigIdValidatorTest extends TestCase
{
    #[DataProvider('containerIds')]
    public function testContainerId(string $value, bool $valid): void
    {
        $this->assertSame($valid, (new ConfigIdValidator())->isValidContainerId($value));
    }

    public static function containerIds(): array
    {
        return [
            ['GTM-ABC123', true],
            ['GTM-K7XQ9PL', true],
            ['G-ABC123XYZ9', false],
            ['gtm-abc123', false],
            ['GTM-', false],
            ['GTM-AB', false],
            ['ABC123', false],
            ['', false],
            ['GTM-ABC123 ', false],
        ];
    }

    #[DataProvider('measurementIds')]
    public function testMeasurementId(string $value, bool $valid): void
    {
        $this->assertSame($valid, (new ConfigIdValidator())->isValidMeasurementId($value));
    }

    public static function measurementIds(): array
    {
        return [
            ['G-ABC123XYZ9', true],
            ['G-1234567890', true],
            ['GTM-ABC123', false],
            ['UA-12345-1', false],
            ['G-', false],
            ['', false],
        ];
    }
}
