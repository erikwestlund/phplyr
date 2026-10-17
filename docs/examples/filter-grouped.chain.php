<?php

// Inside a group, the second closure argument is the group's table, and
// vector helpers such as max() are evaluated per group.
echo phplyr(DataFrame::fromCsv('schools.csv'))
    ->group_by('school')
    ->filter(fn ($r, $g) => $r->score === max('score')($g))
    ->select('school', 'score')
    ->head(4);
