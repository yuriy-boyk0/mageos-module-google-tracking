<?php
declare(strict_types=1);

// Lets PHPStan resolve Magento classes from a plain checkout of github.com/magento/magento2 (tag 2.4.9),
// without Composer or a Magento install. Third-party libraries Magento itself needs (Laminas, Symfony, ...) are
// not available, which is fine: only our module is analysed, against the real Magento interfaces.
//
//   git clone --depth 1 --branch 2.4.9 https://github.com/magento/magento2 /home/user/magento/magento2
//   MAGENTO_SRC=/path/to/magento2 dev/vendor/bin/phpstan analyse -c dev/phpstan.neon
$root = rtrim((string) (getenv('MAGENTO_SRC') ?: '/home/user/magento/magento2'), '/');

spl_autoload_register(static function (string $class) use ($root): void {
    if (strncmp($class, 'Magento\\', 8) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 8)); // e.g. Framework/App/State or Sales/Api/Data/OrderInterface
    [$module, $rest] = array_pad(explode('/', $relative, 2), 2, '');
    $candidates = $module === 'Framework'
        ? [$root . '/lib/internal/Magento/Framework/' . $rest . '.php']
        : [$root . '/app/code/Magento/' . $module . '/' . $rest . '.php'];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// Minimal stand-ins for third-party classes that Magento's own parent classes reference.
if (!class_exists('Zend_Db_Adapter_Exception', false)) {
    class Zend_Db_Adapter_Exception extends \Exception {}
}
if (!class_exists('Zend_Db_Expr', false)) {
    class Zend_Db_Expr {
        public function __toString(): string { return ''; }
    }
}
if (!class_exists('Zend_Db_Select', false)) {
    class Zend_Db_Select {}
}

// This module itself.
spl_autoload_register(static function (string $class): void {
    $prefix = 'MageOS\\GoogleTagManager\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

// Magento's global translation function (app/functions.php).
if (!function_exists('__')) {
    function __(...$args): \Magento\Framework\Phrase
    {
        return new \Magento\Framework\Phrase((string) ($args[0] ?? ''));
    }
}
