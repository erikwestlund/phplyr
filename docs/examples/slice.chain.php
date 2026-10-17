<?php

$schools = phplyr(DataFrame::fromCsv('schools.csv'))->group_by('school')->get();

// The two best students in each of the first three schools.
echo phplyr($schools)
    ->slice_max('score', n: 2)
    ->filter(fn ($r) => $r->school <= 'school-03')
    ->select('school', 'score');
