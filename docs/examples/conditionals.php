<?php

echo Frame::fromRows([
    ['state' => 'NC', 'visits' => 3],
    ['state' => 'NYC', 'visits' => null],
    ['state' => 'CA', 'visits' => 12],
    ['state' => null, 'visits' => 0],
])
    |> mutate(
        name: recode_values('state', ['NC', 'North Carolina'], [['NY', 'NYC'], 'New York'], default: 'other'),
        visits: coalesce('visits', 0),
        busy: if_else(fn ($r) => $r->visits > 5, 'yes', 'no'),
        capped: replace_when('visits', [fn ($r) => $r->visits > 10, 10]),
        any_flag: when_any(fn ($r) => $r->state === 'CA', fn ($r) => $r->visits === 0),
    );
