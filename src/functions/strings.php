<?php

declare(strict_types=1);

/*
 * stringr helpers, re-exported from Phibble\Expr's registry. As in stringr,
 * a pattern is a regular expression (PCRE); wrap it in fixed() to match
 * literally, or regex() for options such as ignore_case.
 *
 * str_replace, str_pad and str_split shadow PHP built-ins in files that
 * import them.
 */

namespace Phplyr;

use Phibble\Expr\Lit;

/** A literal pattern: str_detect('name', fixed('a.b')). */
function fixed(string $pattern): Vec { return Internal::call('fixed', [$pattern], columns: []); }

/** A regular expression with options: regex('^ab', ignore_case: true). */
function regex(string $pattern, bool $ignore_case = false): Vec
{
    return Internal::call('regex', [$pattern], $ignore_case ? ['ignore_case' => true] : [], columns: []);
}

/** Does the pattern occur? */
function str_detect(mixed $x, mixed $pattern, bool $negate = false): Vec { return Internal::call('str_detect', [$x, $pattern], $negate ? ['negate' => true] : []); }

/** Does the string start with the pattern? */
function str_starts(mixed $x, mixed $pattern, bool $negate = false): Vec { return Internal::call('str_starts', [$x, $pattern], $negate ? ['negate' => true] : []); }

/** Does the string end with the pattern? */
function str_ends(mixed $x, mixed $pattern, bool $negate = false): Vec { return Internal::call('str_ends', [$x, $pattern], $negate ? ['negate' => true] : []); }

/** Number of matches. */
function str_count(mixed $x, mixed $pattern): Vec { return Internal::call('str_count', [$x, $pattern]); }

/** The first match, or NA. */
function str_extract(mixed $x, mixed $pattern): Vec { return Internal::call('str_extract', [$x, $pattern]); }

/** Every match, as a list per row (a list-column). */
function str_extract_all(mixed $x, mixed $pattern): Vec { return Internal::call('str_extract_all', [$x, $pattern]); }

/** Replace the first match; \1 refers to a group. */
function str_replace(mixed $x, mixed $pattern, mixed $replacement): Vec { return Internal::call('str_replace', [$x, $pattern, $replacement]); }

/** Replace every match; \1 refers to a group. */
function str_replace_all(mixed $x, mixed $pattern, mixed $replacement): Vec { return Internal::call('str_replace_all', [$x, $pattern, $replacement]); }

/** Split into pieces, as a list per row (a list-column). */
function str_split(mixed $x, mixed $pattern): Vec { return Internal::call('str_split', [$x, $pattern]); }

/** The i-th piece of a split (1-based; negative counts from the end). */
function str_split_i(mixed $x, mixed $pattern, int $i): Vec { return Internal::call('str_split_i', [$x, $pattern, $i]); }

/** Pad to a width: side 'left' (default), 'right' or 'both'. */
function str_pad(mixed $x, int $width, string $side = 'left', string $pad = ' '): Vec
{
    return Internal::call('str_pad', [$x, $width], array_filter(['side' => $side === 'left' ? null : $side, 'pad' => $pad === ' ' ? null : $pad], fn ($v) => $v !== null));
}

/** Truncate to a width, marking the cut with an ellipsis. */
function str_trunc(mixed $x, int $width, string $side = 'right', string $ellipsis = '...'): Vec
{
    return Internal::call('str_trunc', [$x, $width], array_filter(['side' => $side === 'right' ? null : $side, 'ellipsis' => $ellipsis === '...' ? null : $ellipsis], fn ($v) => $v !== null));
}

function str_to_title(mixed $x): Vec { return Internal::call('str_to_title', [$x]); }
function str_to_upper(mixed $x): Vec { return Internal::call('str_to_upper', [$x]); }
function str_to_lower(mixed $x): Vec { return Internal::call('str_to_lower', [$x]); }

/** Trim and collapse internal whitespace. */
function str_squish(mixed $x): Vec { return Internal::call('str_squish', [$x]); }
