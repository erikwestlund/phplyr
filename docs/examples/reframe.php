<?php

echo DataFrame::fromCsv('schools.csv')
    |> filter(fn ($r) => $r->school <= 'school-02')
    |> group_by('school')
    |> reframe(
        p: [0.25, 0.5, 0.75],
        score: fn ($g) => array_map(fn ($p) => Stats::quantile($g->column('score'), $p), [0.25, 0.5, 0.75]),
    );
