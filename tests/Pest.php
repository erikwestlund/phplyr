<?php

declare(strict_types=1);

use Phplyr\Stats;

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
