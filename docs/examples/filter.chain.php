<?php

$schools = DataFrame::fromCsv('schools.csv');

// Several conditions are combined with AND; NA counts as false.
echo phplyr($schools)
    ->filter(fn ($r) => $r->prior_score > 75, between('study_hours', 2, 6))
    ->head(4);

// filter_out() drops the rows where every condition is true.
echo "\n", phplyr($schools)->filter_out(fn ($r) => $r->prior_score < 70)->count();
