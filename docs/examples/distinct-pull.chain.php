<?php

$housing = DataFrame::fromCsv('housing.csv');

echo phplyr($housing)->distinct('bedrooms')->arrange('bedrooms');
echo "\n", json_encode(phplyr($housing)->arrange('area_m2')->pull('monthly_rent')), "\n";
echo json_encode(phplyr($housing)->pull(-1)), "\n";
