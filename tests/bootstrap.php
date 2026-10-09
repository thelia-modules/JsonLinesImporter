<?php

declare(strict_types=1);

// The unit suite runs on the module's own vendor (composer install at the module root): it needs
// opis/json-schema and PHPUnit, never the core. The integration suite runs from a shop, whose
// autoloader (THELIA_VENDOR_AUTOLOAD, or the shop the module is installed in) provides the core.
$candidates = array_filter([
    getenv('THELIA_VENDOR_AUTOLOAD') ?: null,
    // composer install at the module root
    dirname(__DIR__).'/vendor/autoload.php',
    // local/modules/JsonLinesImporter
    __DIR__.'/../../../../vendor/autoload.php',
    // vendor/thelia/modules/JsonLinesImporter
    __DIR__.'/../../../../../vendor/autoload.php',
]);

foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $loader = require $candidate;

        // A shop autoloader does not know the module namespace: Thelia registers it at boot.
        if ($loader instanceof Composer\Autoload\ClassLoader) {
            $loader->addPsr4('JsonLinesImporter\\', dirname(__DIR__).'/');
            $loader->addPsr4('JsonLinesImporter\\Tests\\', __DIR__.'/');
        }

        return;
    }
}

fwrite(\STDERR, "No vendor/autoload.php found: run composer install at the module root, or set THELIA_VENDOR_AUTOLOAD.\n");
exit(1);
