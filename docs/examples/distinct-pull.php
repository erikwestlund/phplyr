<?php

$housing = Frame::fromCsv('housing.csv');

echo $housing |> distinct('bedrooms') |> arrange('bedrooms');
echo "\n", json_encode($housing |> arrange('area_m2') |> pull('monthly_rent')), "\n";
echo json_encode($housing |> pull(-1)), "\n";
