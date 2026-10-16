<?php

declare(strict_types=1);

namespace Phplyr;

/** @internal Helpers shared by the verb functions. */
final class Internal
{
    /** Verbs that name their outputs need named arguments. */
    public static function assertNamed(array $args, string $verb): void
    {
        foreach (array_keys($args) as $key) {
            if (\is_int($key)) {
                throw new \InvalidArgumentException(
                    "{$verb}() needs named arguments, for example {$verb}(total: ...)."
                );
            }
        }
    }

    public static function callStats(string $function, mixed ...$args): mixed
    {
        if (! \function_exists($function)) {
            throw new \RuntimeException(
                "{$function}() is not available. Install or update the php_stats extension (erikwestlund/php-stats)."
            );
        }

        return $function(...$args);
    }
}
