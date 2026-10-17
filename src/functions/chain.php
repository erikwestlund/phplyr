<?php

declare(strict_types=1);

/*
 * Two ways to run verbs without the PHP 8.5 pipe operator. Both give the
 * same result as $t |> filter(...) |> head(5):
 *
 *     phplyr($t)->filter(...)->head(5)->get();
 *     pipe($t, filter(...), head(5));
 */

namespace Phplyr;

use Phibble\Contracts\Table;

/**
 * Start a method chain on a table: phplyr($t)->filter(...)->head(5).
 * Each method runs the phplyr verb of that name. get() returns the table,
 * and verbs that return something else, such as pull(), return it plainly.
 */
function phplyr(Table|Chain $table): Chain
{
    return $table instanceof Chain ? $table : new Chain($table);
}

/**
 * Apply steps to a value in order: pipe($t, filter(...), head(5)) is
 * $t |> filter(...) |> head(5). A step is any callable.
 */
function pipe(mixed $value, callable ...$steps): mixed
{
    foreach ($steps as $step) {
        $value = $step($value);
    }

    return $value;
}
