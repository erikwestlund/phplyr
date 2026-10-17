<?php

use function Phplyr\{floor_date, str_detect, str_extract, str_replace_all, str_to_title, ymd};

$notes = DataFrame::fromRows([
    ['when' => '2024-01-31', 'note' => 'paid INV-1042 late'],
    ['when' => '2024-02-29', 'note' => 'refund inv-77'],
    ['when' => '2024-03-15', 'note' => 'no invoice'],
]);

// Dates: parse, round, shift by a month (an invalid date gives NA), weekday.
echo $notes
    |> mutate(
        date: ymd('when'),
        month_start: floor_date(ymd('when'), 'month'),
        next_month: ymd('when')->add(months(1)),
        weekday: wday(ymd('when'), week_start: 1),
    )
    |> select(except('note')), "\n";

// Strings: patterns are regular expressions unless wrapped in fixed().
echo $notes
    |> mutate(
        invoice: str_extract('note', regex('inv-\d+', ignore_case: true)),
        literal: str_detect('note', fixed('INV')),
        masked: str_replace_all('note', '\d', '#'),
        title: str_to_title('note'),
    )
    |> select(except('when', 'note'));
