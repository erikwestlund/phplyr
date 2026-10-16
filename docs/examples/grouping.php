<?php

$schools = Frame::fromCsv('schools.csv') |> group_by('school');

echo json_encode([
    'groups' => $schools |> group_vars(),
    'n_groups' => $schools |> n_groups(),
    'sizes' => \array_slice($schools |> group_size(), 0, 4),
]), "\n\n";

// Grouped mutate: helpers are evaluated within each group.
echo $schools
    |> mutate(centered: fn ($r, $g) => round($r->score - mean('score')($g), 2), rank_in_school: min_rank(desc('score')))
    |> ungroup()
    |> head(4);

// One table per group.
$parts = $schools |> group_split();
echo "\n", \count($parts), ' tables; the first has ', $parts[0]->nrow(), " rows\n";
