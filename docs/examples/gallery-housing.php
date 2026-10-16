<?php

// Price per square meter by number of bedrooms, with each flat's
// position relative to its bedroom group.
$housing = Frame::fromCsv('housing.csv')
    |> mutate(per_m2: fn ($r) => round($r->monthly_rent / $r->area_m2, 2));

echo $housing
    |> group_by('bedrooms')
    |> summarise(across(['area_m2', 'monthly_rent', 'per_m2'], ['mean' => mean(...), 'max' => max(...)]), flats: n())
    |> mutate(across(where('double'), fn ($v) => round($v, 1)));

echo "\n", $housing
    |> mutate(vs_group: fn ($r, $g) => round($r->per_m2 - mean('per_m2')($g), 2), _by: 'bedrooms')
    |> arrange('bedrooms', desc('vs_group'));
