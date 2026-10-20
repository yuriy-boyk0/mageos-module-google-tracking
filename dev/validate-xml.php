<?php
declare(strict_types=1);

// Validates the module's XML files against the real Magento XSDs from a checkout of magento2 (see phpstan-magento-bootstrap.php).
//   MAGENTO_SRC=/path/to/magento2 php dev/validate-xml.php
$root = rtrim((string) (getenv('MAGENTO_SRC') ?: '/home/user/magento/magento2'), '/');
$module = dirname(__DIR__);

function resolveUrn(string $urn, string $root): ?string
{
    if (preg_match('#^urn:magento:framework:(.+)$#', $urn, $m)) {
        return $root . '/lib/internal/Magento/Framework/' . $m[1];
    }
    if (preg_match('#^urn:magento:module:Magento_([A-Za-z0-9]+):(.+)$#', $urn, $m)) {
        return $root . '/app/code/Magento/' . $m[1] . '/' . $m[2];
    }
    return null;
}

// Resolve urn: references inside the XSDs too (xs:include / xs:import), by rewriting them into a temp copy tree.
function localise(string $xsdPath, string $root, string $tmp, array &$done): string
{
    $key = realpath($xsdPath);
    if (isset($done[$key])) {
        return $done[$key];
    }
    $target = $tmp . '/' . md5($key) . '.xsd';
    $done[$key] = $target;
    $content = (string) file_get_contents($xsdPath);
    $content = preg_replace_callback('#schemaLocation="(urn:magento:[^"]+)"#', function ($m) use ($root, $tmp, &$done) {
        $path = resolveUrn($m[1], $root);
        return $path && is_file($path) ? 'schemaLocation="' . localise($path, $root, $tmp, $done) . '"' : $m[0];
    }, $content);
    // relative includes (e.g. "../../Foo.xsd") must resolve against the original directory
    $content = preg_replace_callback('#schemaLocation="(?!urn:|/|http)([^"]+)"#', function ($m) use ($xsdPath, $root, $tmp, &$done) {
        $path = dirname($xsdPath) . '/' . $m[1];
        return is_file($path) ? 'schemaLocation="' . localise($path, $root, $tmp, $done) . '"' : $m[0];
    }, $content);
    file_put_contents($target, $content);
    return $target;
}

$tmp = sys_get_temp_dir() . '/mageos-xsd-' . getmypid();
mkdir($tmp);
$done = [];
$failures = 0;
$files = array_merge(glob($module . '/etc/*.xml'), glob($module . '/etc/*/*.xml'), glob($module . '/view/*/layout/*.xml'));
foreach ($files as $file) {
    $dom = new DOMDocument();
    $dom->load($file);
    $loc = $dom->documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'noNamespaceSchemaLocation');
    $xsd = $loc ? resolveUrn($loc, $root) : null;
    if (!$xsd || !is_file($xsd)) {
        echo "SKIP  " . substr($file, strlen($module) + 1) . " (no resolvable schema: $loc)\n";
        continue;
    }
    libxml_use_internal_errors(true);
    $ok = $dom->schemaValidate(localise($xsd, $root, $tmp, $done));
    $errors = libxml_get_errors();
    libxml_clear_errors();
    echo ($ok ? 'OK    ' : 'FAIL  ') . substr($file, strlen($module) + 1) . "\n";
    if (!$ok) {
        $failures++;
        foreach (array_slice($errors, 0, 5) as $e) {
            echo '      line ' . $e->line . ': ' . trim($e->message) . "\n";
        }
    }
}
array_map('unlink', glob($tmp . '/*'));
rmdir($tmp);
exit($failures > 0 ? 1 : 0);
