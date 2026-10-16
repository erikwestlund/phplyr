<?php

declare(strict_types=1);

/*
 * Scalar expression helpers, re-exported from Phibble\Expr's registry so a
 * pipeline can stay inspectable: mutate(z: round(col('x')->div(3), 2)).
 *
 * Several names (abs, sqrt, exp, log, log10, round, floor, str_replace)
 * shadow PHP built-ins in files that import them. Import only the ones you
 * use, or call the built-ins with a leading backslash.
 */

namespace Phplyr;

function is_na(mixed $x): Vec { return Internal::call('is_na', [$x]); }
function abs(mixed $x): Vec { return Internal::call('abs', [$x]); }
function sqrt(mixed $x): Vec { return Internal::call('sqrt', [$x]); }
function exp(mixed $x): Vec { return Internal::call('exp', [$x]); }

/** Natural logarithm, or base $base. */
function log(mixed $x, ?float $base = null): Vec { return Internal::call('log', [$x], $base === null ? [] : ['base' => $base]); }
function log10(mixed $x): Vec { return Internal::call('log10', [$x]); }

/** R's round(): halves to even, at $digits decimal places. */
function round(mixed $x, int $digits = 0): Vec { return Internal::call('round', [$x], $digits === 0 ? [] : ['digits' => $digits]); }
function floor(mixed $x): Vec { return Internal::call('floor', [$x]); }
function ceiling(mixed $x): Vec { return Internal::call('ceiling', [$x]); }
function as_int(mixed $x): Vec { return Internal::call('as_int', [$x]); }
function as_float(mixed $x): Vec { return Internal::call('as_float', [$x]); }
function as_string(mixed $x): Vec { return Internal::call('as_string', [$x]); }
function as_bool(mixed $x): Vec { return Internal::call('as_bool', [$x]); }
function str_lower(mixed $x): Vec { return Internal::call('str_lower', [$x]); }
function str_upper(mixed $x): Vec { return Internal::call('str_upper', [$x]); }
function str_length(mixed $x): Vec { return Internal::call('str_length', [$x]); }

/** Trim whitespace: side 'both', 'left' or 'right'. */
function str_trim(mixed $x, string $side = 'both'): Vec { return Internal::call('str_trim', [$x], $side === 'both' ? [] : ['side' => $side]); }

/** Substring by 1-based positions, as stringr's str_sub(); negative counts from the end. */
function str_sub(mixed $x, int $start = 1, ?int $end = null): Vec { return Internal::call('str_sub', $end === null ? [$x, $start] : [$x, $start, $end]); }

/** Fixed-string tests. */
function str_detect(mixed $x, string $pattern, bool $negate = false): Vec { return Internal::call('str_detect', [$x, $pattern], $negate ? ['negate' => true] : []); }
function str_starts(mixed $x, string $pattern, bool $negate = false): Vec { return Internal::call('str_starts', [$x, $pattern], $negate ? ['negate' => true] : []); }
function str_ends(mixed $x, string $pattern, bool $negate = false): Vec { return Internal::call('str_ends', [$x, $pattern], $negate ? ['negate' => true] : []); }

/** Replace the first (str_replace) or every (str_replace_all) fixed match. */
function str_replace(mixed $x, string $pattern, string $replacement): Vec { return Internal::call('str_replace', [$x, $pattern, $replacement]); }
function str_replace_all(mixed $x, string $pattern, string $replacement): Vec { return Internal::call('str_replace_all', [$x, $pattern, $replacement]); }

/** Paste values together; NA becomes "NA", as in R. Strings after the first are literals. */
function paste0(mixed ...$xs): Vec { return Internal::call('paste0', array_values($xs)); }
function paste(mixed ...$xs): Vec
{
    $sep = $xs['sep'] ?? ' ';
    unset($xs['sep']);

    return Internal::call('paste', array_values($xs), $sep === ' ' ? [] : ['sep' => $sep]);
}
