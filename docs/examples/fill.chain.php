<?php

echo phplyr(DataFrame::fromRows([
    ['quarter' => 'Q1', 'year' => 2024, 'sales' => 10],
    ['quarter' => 'Q2', 'year' => null, 'sales' => null],
    ['quarter' => 'Q3', 'year' => null, 'sales' => 14],
]))
    ->fill('year')
    ->replace_na(['sales' => 0]);
