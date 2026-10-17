<?php

$schools = phplyr(DataFrame::fromCsv('schools.csv'))->group_by('school')->get();

echo json_encode([
    'groups' => phplyr($schools)->group_vars(),
    'n_groups' => phplyr($schools)->n_groups(),
    'sizes' => \array_slice(phplyr($schools)->group_size(), 0, 4),
]), "\n\n";

// Grouped mutate: helpers are evaluated within each group.
echo phplyr($schools)
    ->mutate(centered: fn ($r, $g) => round($r->score - mean('score')($g), 2), rank_in_school: min_rank(desc('score')))
    ->ungroup()
    ->head(4);

// One table per group.
$parts = phplyr($schools)->group_split();
echo "\n", \count($parts), ' tables; the first has ', $parts[0]->nrow(), " rows\n";
