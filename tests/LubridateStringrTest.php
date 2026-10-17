<?php

declare(strict_types=1);

use Phibble\CalendarDate;
use Phibble\Expr\Clock;
use Phibble\DataFrame;
use Phibble\Type;
use Phplyr\Vec;
use function Phplyr\{ceiling_date, col, day, days, difftime, dmy, fixed, floor_date, force_tz, hour, hours, lit, mdy, minute, minutes, month, months, now, parse_date_time, quarter, regex, round_date, second, str_count, str_detect, str_ends, str_extract, str_extract_all, str_length, str_pad, str_replace, str_replace_all, str_split, str_split_i, str_squish, str_starts, str_to_title, str_to_upper, str_trunc, today, wday, weeks, with_tz, yday, year, years, ymd, ymd_hms, mutate, pull};

/*
 * phibble's lubridate and stringr reference cases (lubridate 1.9.5, stringr
 * 1.6.0, written by phibble's tests/r/lubridate_stringr.R), run through
 * phplyr's re-exported helpers and mutate().
 */
function lsExpected(string $case): array
{
    static $all = null;
    $all ??= json_decode(file_get_contents(fixturePath('lubridate-stringr.json')), true);

    return $all[$case];
}

function lsFrame(): DataFrame
{
    return DataFrame::fromCsvString(implode("\n", [
        'd,t,s',
        '2024-01-31,2024-01-31 23:59:59,Apple pie',
        '2024-02-29,2024-03-10 02:30:00,banana split',
        '2023-12-31,2023-12-31 12:00:00,NA',
        'NA,NA,"  cherry   tart  "',
        '2024-07-04,2024-07-04 08:15:30,apple.APPLE',
    ]), types: 'DTc', trimWs: false);
}

/** A value as the R script wrote it. */
function lsFormat(mixed $v): mixed
{
    return match (true) {
        $v === null => null,
        $v instanceof CalendarDate => $v->format('Y-m-d'),
        $v instanceof DateTimeInterface => $v->format('Y-m-d H:i:s O'),
        is_array($v) => array_map(lsFormat(...), $v),
        is_int($v) => (float) $v,
        is_float($v) => \round($v, 9),
        default => $v,
    };
}

function lsWith(array $values): DataFrame
{
    return DataFrame::fromColumns(['v' => $values]);
}

dataset('lubridate and stringr', function () {
    $d = col('d');
    $t = col('t');
    $s = col('s');
    $v = col('v');

    return [
        'ymd' => ['ymd', fn () => lsWith(['2024-01-31', '20240229', '2023/12/31', null, 'bad']), ymd($v)],
        'ymd_hms' => ['ymd_hms', fn () => lsWith(['2024-01-31 23:59:59', '2024-02-29T10:00:00', '2023/12/31 12:00', null, 'bad']), ymd_hms($v)],
        'dmy' => ['dmy', fn () => lsWith(['31/01/2024', '29-02-2024', '31.12.2023', null, '32/01/2024']), dmy($v)],
        'mdy' => ['mdy', fn () => lsWith(['01/31/2024', '02-29-2024', '12.31.2023', null, '13/01/2024']), mdy($v)],
        'parse_dt' => ['parse_dt', fn () => lsWith(['2024-01-31 23:59:59', '31/01/2024 10:00:00', '2023-12-31 12:00:00', null, 'x']), parse_date_time($v, ['Ymd HMS', 'dmY HMS'])],
        'year' => ['year', lsFrame(...), year($d)],
        'month' => ['month', lsFrame(...), month($d)],
        'day' => ['day', lsFrame(...), day($d)],
        'wday' => ['wday', lsFrame(...), wday($d)],
        'wday_mon' => ['wday_mon', lsFrame(...), wday($d, week_start: 1)],
        'yday' => ['yday', lsFrame(...), yday($d)],
        'quarter' => ['quarter', lsFrame(...), quarter($d)],
        'hour' => ['hour', lsFrame(...), hour($t)],
        'minute' => ['minute', lsFrame(...), minute($t)],
        'second' => ['second', lsFrame(...), second($t)],
        'year_t' => ['year_t', lsFrame(...), year($t)],
        'floor_hour' => ['floor_hour', lsFrame(...), floor_date($t, 'hour')],
        'floor_15min' => ['floor_15min', lsFrame(...), floor_date($t, '15 minutes')],
        'floor_week' => ['floor_week', lsFrame(...), floor_date($t, 'week')],
        'floor_month_d' => ['floor_month_d', lsFrame(...), floor_date($d, 'month')],
        'floor_quarter_d' => ['floor_quarter_d', lsFrame(...), floor_date($d, 'quarter')],
        'floor_year_d' => ['floor_year_d', lsFrame(...), floor_date($d, 'year')],
        'ceiling_month_d' => ['ceiling_month_d', lsFrame(...), ceiling_date($d, 'month')],
        'ceiling_hour' => ['ceiling_hour', lsFrame(...), ceiling_date($t, 'hour')],
        'round_day' => ['round_day', lsFrame(...), round_date($t, 'day')],
        'round_month_d' => ['round_month_d', lsFrame(...), round_date($d, 'month')],
        'plus_days' => ['plus_days', lsFrame(...), $d->add(days(10))],
        'plus_months' => ['plus_months', lsFrame(...), $d->add(months(1))],
        'plus_years' => ['plus_years', lsFrame(...), $d->add(years(1))],
        'minus_weeks' => ['minus_weeks', lsFrame(...), $d->sub(weeks(2))],
        'plus_hours' => ['plus_hours', lsFrame(...), $t->add(hours(5))],
        'plus_minutes' => ['plus_minutes', lsFrame(...), $t->add(minutes(90))],
        'diff_days' => ['diff_days', lsFrame(...), $d->sub(ymd(lit('2024-01-01')))],
        'difftime_hours' => ['difftime_hours', lsFrame(...), difftime($t, ymd_hms(lit('2024-01-01 00:00:00')), units: 'hours')],
        'difftime_weeks' => ['difftime_weeks', lsFrame(...), difftime($d, ymd(lit('2023-12-31')), units: 'weeks')],
        'with_tz' => ['with_tz', lsFrame(...), with_tz($t, 'America/New_York')],
        'force_tz' => ['force_tz', lsFrame(...), force_tz($t, 'America/New_York')],

        'str_detect' => ['str_detect', lsFrame(...), str_detect($s, 'a.p')],
        'str_detect_fixed' => ['str_detect_fixed', lsFrame(...), str_detect($s, fixed('.'))],
        'str_detect_ic' => ['str_detect_ic', lsFrame(...), str_detect($s, regex('apple', ignore_case: true))],
        'str_detect_negate' => ['str_detect_negate', lsFrame(...), str_detect($s, '^a', negate: true)],
        'str_starts' => ['str_starts', lsFrame(...), str_starts($s, 'a|A')],
        'str_ends' => ['str_ends', lsFrame(...), str_ends($s, 't|E')],
        'str_count' => ['str_count', lsFrame(...), str_count($s, 'a')],
        'str_count_fixed' => ['str_count_fixed', lsFrame(...), str_count($s, fixed('p'))],
        'str_count_class' => ['str_count_class', lsFrame(...), str_count($s, '[aeiou]')],
        'str_extract' => ['str_extract', lsFrame(...), str_extract($s, '[a-z]+')],
        'str_extract_all' => ['str_extract_all', lsFrame(...), str_extract_all($s, 'p+')],
        'str_split' => ['str_split', lsFrame(...), str_split($s, ' ')],
        'str_split_i' => ['str_split_i', lsFrame(...), str_split_i($s, ' ', 2)],
        'str_split_i_neg' => ['str_split_i_neg', lsFrame(...), str_split_i($s, ' ', -1)],
        'str_replace' => ['str_replace', lsFrame(...), str_replace($s, 'a', 'A')],
        'str_replace_all' => ['str_replace_all', lsFrame(...), str_replace_all($s, '([aeiou])', '<\1>')],
        'str_replace_all_fixed' => ['str_replace_all_fixed', lsFrame(...), str_replace_all($s, fixed('.'), '!')],
        'str_replace_na' => ['str_replace_na', lsFrame(...), str_replace($s, 'pie', lit(null))],
        'str_pad' => ['str_pad', fn () => lsWith(['a', 'abc', null, 'abcdef', 'ab']), str_pad($v, 5)],
        'str_pad_right' => ['str_pad_right', fn () => lsWith(['a', 'abc', null, 'abcdef', 'ab']), str_pad($v, 5, side: 'right', pad: '*')],
        'str_pad_both' => ['str_pad_both', fn () => lsWith(['a', 'abc', null, 'abcdef', 'ab']), str_pad($v, 6, side: 'both', pad: '-')],
        'str_trunc' => ['str_trunc', lsFrame(...), str_trunc($s, 8)],
        'str_trunc_left' => ['str_trunc_left', lsFrame(...), str_trunc($s, 8, side: 'left')],
        'str_trunc_center' => ['str_trunc_center', lsFrame(...), str_trunc($s, 9, side: 'center', ellipsis: '~')],
        'str_to_title' => ['str_to_title', fn () => lsWith(['hello world', "o'neil mcdonald", 'ALL CAPS', null, 'x-ray vision']), str_to_title($v)],
        'str_to_upper' => ['str_to_upper', lsFrame(...), str_to_upper($s)],
        'str_squish' => ['str_squish', lsFrame(...), str_squish($s)],
        'str_length' => ['str_length', lsFrame(...), str_length($s)],
    ];
});

it('matches lubridate and stringr through phplyr', function (string $case, Closure $frame, Vec $expr) {
    expect($expr->expr)->not->toBeNull();
    set_error_handler(fn () => true, E_USER_WARNING);
    try {
        $out = $frame() |> mutate(out: $expr) |> pull('out');
    } finally {
        restore_error_handler();
    }
    expect(lsFormat($out))->toBe(lsFormat(lsExpected($case)));
})->with('lubridate and stringr');

it('keeps dates as dates and reads the frozen clock', function () {
    $out = lsFrame() |> mutate(next: col('d')->add(days(1)), m: floor_date('d', 'month'));
    expect($out->type('next'))->toBe(Type::Date)->and($out->type('m'))->toBe(Type::Date);
    Clock::freeze(new DateTimeImmutable('2024-05-06 23:30:00', new DateTimeZone('UTC')));
    try {
        $out = DataFrame::fromColumns(['x' => [1]]) |> mutate(d: today(), nz: today(tz: 'Pacific/Auckland'));
        expect($out->column('d')[0]->format('Y-m-d'))->toBe('2024-05-06')
            ->and($out->column('nz')[0]->format('Y-m-d'))->toBe('2024-05-07');
    } finally {
        Clock::freeze(null);
    }
});