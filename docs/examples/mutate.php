<?php

echo Frame::fromCsv('housing.csv')
    |> mutate(
        rent_per_m2: fn ($r) => round($r->monthly_rent / $r->area_m2, 2),
        size: if_else(fn ($r) => $r->area_m2 >= 60, 'large', 'small'),
        rank: min_rank(desc('monthly_rent')),
    )
    |> arrange('rank');
