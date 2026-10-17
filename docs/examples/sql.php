<?php

// Load the schools data into an in-memory SQLite database.
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE schools (school TEXT, study_hours REAL, prior_score REAL, score REAL)');
$insert = $pdo->prepare('INSERT INTO schools VALUES (?, ?, ?, ?)');
foreach (DataFrame::fromCsv('schools.csv')->toRows() as $row) {
    $insert->execute(array_values($row));
}

// The same verbs build a query. Nothing runs until collect().
$query = tbl($pdo, 'schools')
    |> filter(col('study_hours')->ge(5))
    |> group_by('school')
    |> summarise(students: n(), avg_score: mean('score'))
    |> filter(col('students')->ge(12))
    |> arrange(desc('avg_score'));

echo $query |> show_query(), "\n\n";
echo $query |> collect();
