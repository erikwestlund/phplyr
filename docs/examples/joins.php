<?php

$schools = DataFrame::fromCsv('schools.csv')
    |> group_by('school')
    |> summarise(avg_score: mean('score'));

$districts = DataFrame::fromRows([
    ['school_id' => 'school-01', 'district' => 'North'],
    ['school_id' => 'school-02', 'district' => 'North'],
    ['school_id' => 'school-03', 'district' => 'South'],
    ['school_id' => 'school-99', 'district' => 'East'],
]);

echo $schools |> inner_join($districts, by: ['school' => 'school_id']);
echo "\n", $schools |> left_join($districts, by: ['school' => 'school_id']) |> head(5);
echo "\n", $districts |> anti_join($schools, by: ['school_id' => 'school']);
