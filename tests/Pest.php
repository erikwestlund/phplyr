<?php

declare(strict_types=1);

use Phplyr\Stats;

/*
 * Until erikwestlund/phibble is installed, load the stand-in classes in
 * tests/Support/phibble. Composer's autoloader runs first, so the real
 * package always wins once it is present.
 */
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Phibble\\')) {
        $file = __DIR__.'/Support/phibble/'.str_replace('\\', '/', substr($class, 8)).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function fixturePath(string $name): string
{
    return __DIR__.'/Fixtures/'.$name;
}

/** Run a test body once with php_stats (when loaded) and once in pure PHP. */
function bothBackends(Closure $body): void
{
    $backends = extension_loaded('php_stats') ? [true, false] : [false];
    try {
        foreach ($backends as $useExtension) {
            Stats::$useExtension = $useExtension;
            $body($useExtension);
        }
    } finally {
        Stats::$useExtension = true;
    }
}
