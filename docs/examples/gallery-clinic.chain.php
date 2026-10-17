<?php

// Calls by staffing level and visitor volume, as a cross-tabulation.
echo phplyr(DataFrame::fromCsv('clinic.csv'))
    ->mutate(
        staffing: case_when([fn ($r) => $r->staff < 4, 'low'], [fn ($r) => $r->staff < 6, 'mid'], default: 'high'),
        volume: if_else(fn ($r) => $r->visitors >= 250, 'busy', 'quiet'),
    )
    ->group_by('staffing', 'volume')
    ->summarise(mean_calls: mean('calls'), _groups: 'drop')
    ->mutate(mean_calls: fn ($r) => round($r->mean_calls, 2))
    ->pivot_wider(names_from: 'volume', values_from: 'mean_calls', names_sort: true)
    ->arrange(fn ($r) => array_search($r->staffing, ['low', 'mid', 'high']));
