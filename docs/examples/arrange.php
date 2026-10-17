<?php

echo DataFrame::fromCsv('housing.csv')
    |> arrange('bedrooms', desc(fn ($r) => $r->monthly_rent / $r->area_m2));
