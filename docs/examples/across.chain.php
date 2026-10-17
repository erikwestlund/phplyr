<?php

$schools = phplyr(DataFrame::fromCsv('schools.csv'))->group_by('school')->get();

// Phplyr helpers can be passed by name; each gets the column name.
echo phplyr($schools)
    ->summarise(across(where('numeric'), ['avg' => mean(...), 'sd' => sd(...)]))
    ->head(3);

// Other closures get the column's values (summarise) or each value (mutate).
echo "\n", phplyr($schools)
    ->summarise(across(['study_hours', 'score'], fn (array $v) => round(\max($v) - \min($v), 1), names: 'range_{col}'))
    ->head(3);
echo "\n", phplyr($schools)->ungroup()->mutate(across(ends_with('score'), fn ($v) => round($v)))->head(3);
