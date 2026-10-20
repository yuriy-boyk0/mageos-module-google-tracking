<?php
declare(strict_types=1);

// Bootstrap for the Magento-free unit tests: run `composer install` in dev/ once, then
// dev/vendor/bin/phpunit -c phpunit.xml.dist  (from the module root).
require __DIR__ . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'MageOS\\GoogleTagManager\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
