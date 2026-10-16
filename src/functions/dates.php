<?php

declare(strict_types=1);

/*
 * lubridate helpers, re-exported from Phibble\Expr's registry. Periods such
 * as months(1) are expressions, so col('d')->add(months(1)) works; month or
 * year arithmetic that lands on an invalid date gives NA, as in lubridate.
 * Phibble\Expr\Clock::freeze() fixes today() and now() in tests.
 */

namespace Phplyr;

use Phibble\Expr\Lit;

/** @internal tz as a named option, only when given. */
function _tz(?string $tz): array
{
    return $tz === null ? [] : ['tz' => $tz];
}

// Parsing
function ymd(mixed $x, ?string $tz = null): Vec { return Internal::call('ymd', [$x], _tz($tz)); }
function dmy(mixed $x, ?string $tz = null): Vec { return Internal::call('dmy', [$x], _tz($tz)); }
function mdy(mixed $x, ?string $tz = null): Vec { return Internal::call('mdy', [$x], _tz($tz)); }
function ymd_hms(mixed $x, ?string $tz = null): Vec { return Internal::call('ymd_hms', [$x], _tz($tz)); }
function dmy_hms(mixed $x, ?string $tz = null): Vec { return Internal::call('dmy_hms', [$x], _tz($tz)); }
function mdy_hms(mixed $x, ?string $tz = null): Vec { return Internal::call('mdy_hms', [$x], _tz($tz)); }

/** Parse with lubridate orders: parse_date_time('when', ['Ymd HMS', 'dmY HMS']). */
function parse_date_time(mixed $x, string|array $orders, ?string $tz = null): Vec
{
    return Internal::call('parse_date_time', [$x, new Lit(array_values((array) $orders))], _tz($tz));
}

// Parts
function year(mixed $x): Vec { return Internal::call('year', [$x]); }
function month(mixed $x): Vec { return Internal::call('month', [$x]); }
function day(mixed $x): Vec { return Internal::call('day', [$x]); }

/** Day of the week, 1-7; Sunday is 1 unless week_start says otherwise (1 = Monday). */
function wday(mixed $x, ?int $week_start = null): Vec { return Internal::call('wday', [$x], $week_start === null ? [] : ['week_start' => $week_start]); }
function yday(mixed $x): Vec { return Internal::call('yday', [$x]); }
function quarter(mixed $x): Vec { return Internal::call('quarter', [$x]); }
function hour(mixed $x): Vec { return Internal::call('hour', [$x]); }
function minute(mixed $x): Vec { return Internal::call('minute', [$x]); }
function second(mixed $x): Vec { return Internal::call('second', [$x]); }

// Rounding: unit is 'second', 'minute', 'hour', 'day', 'week', 'month', 'quarter' or 'year', optionally with a multiple ('15 minutes').
function floor_date(mixed $x, string $unit, ?int $week_start = null): Vec { return Internal::call('floor_date', [$x, $unit], $week_start === null ? [] : ['week_start' => $week_start]); }
function ceiling_date(mixed $x, string $unit, ?int $week_start = null): Vec { return Internal::call('ceiling_date', [$x, $unit], $week_start === null ? [] : ['week_start' => $week_start]); }
function round_date(mixed $x, string $unit, ?int $week_start = null): Vec { return Internal::call('round_date', [$x, $unit], $week_start === null ? [] : ['week_start' => $week_start]); }

// Periods, for date arithmetic.
function seconds(mixed $n = 1): Vec { return Internal::call('seconds', [$n], columns: []); }
function minutes(mixed $n = 1): Vec { return Internal::call('minutes', [$n], columns: []); }
function hours(mixed $n = 1): Vec { return Internal::call('hours', [$n], columns: []); }
function days(mixed $n = 1): Vec { return Internal::call('days', [$n], columns: []); }
function weeks(mixed $n = 1): Vec { return Internal::call('weeks', [$n], columns: []); }
function months(mixed $n = 1): Vec { return Internal::call('months', [$n], columns: []); }
function years(mixed $n = 1): Vec { return Internal::call('years', [$n], columns: []); }

/** The difference x - y in units 'secs', 'mins', 'hours', 'days' or 'weeks', as a double. */
function difftime(mixed $x, mixed $y, ?string $units = null): Vec
{
    return Internal::call('difftime', [$x, $y], $units === null ? [] : ['units' => $units], columns: [0, 1]);
}

/** The same instant in another time zone. */
function with_tz(mixed $x, string $tz): Vec { return Internal::call('with_tz', [$x, $tz]); }

/** The same clock time in another time zone. */
function force_tz(mixed $x, string $tz): Vec { return Internal::call('force_tz', [$x, $tz]); }

function today(?string $tz = null): Vec { return Internal::call('today', [], _tz($tz)); }
function now(?string $tz = null): Vec { return Internal::call('now', [], _tz($tz)); }
