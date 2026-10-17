<?php

$schools = DataFrame::fromCsv('schools.csv');

echo implode(', ', (phplyr($schools)->select('school', ends_with('score'))->get())->columns()), "\n";
echo implode(', ', (phplyr($schools)->select(except('school'))->get())->columns()), "\n";
echo implode(', ', (phplyr($schools)->select(where('numeric'))->get())->columns()), "\n";
echo implode(', ', (phplyr($schools)->select(['hours' => 'study_hours'], everything())->get())->columns()), "\n";
echo implode(', ', (phplyr($schools)->relocate('score', _before: 'study_hours')->get())->columns()), "\n";
echo implode(', ', (phplyr($schools)->rename_with(strtoupper(...), contains('score'))->get())->columns()), "\n";
