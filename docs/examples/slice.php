<?php

$schools = Frame::fromCsv('schools.csv') |> group_by('school');

// The two best students in each of the first three schools.
echo $schools
    |> slice_max('score', n: 2)
    |> filter(fn ($r) => $r->school <= 'school-03')
    |> select('school', 'score');
