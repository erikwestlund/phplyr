<?php

$housing = DataFrame::fromCsv('housing.csv');

// _keep: 'none' keeps only new columns (and grouping columns).
echo $housing |> mutate(rent_k: fn ($r) => $r->monthly_rent / 1000, _keep: 'none') |> head(3);

// _by groups for this call only; mean() is recycled within each group.
echo "\n", $housing
    |> mutate(vs_bedroom_avg: fn ($r, $g) => $r->monthly_rent - mean('monthly_rent')($g), _by: 'bedrooms', _after: 'bedrooms')
    |> head(4);
