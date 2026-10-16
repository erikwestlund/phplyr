```php
// Load the schools data into an in-memory SQLite database.
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE schools (school TEXT, study_hours REAL, prior_score REAL, score REAL)');
$insert = $pdo->prepare('INSERT INTO schools VALUES (?, ?, ?, ?)');
foreach (Frame::fromCsv('schools.csv')->toRows() as $row) {
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
```

```{.text .output}
SELECT * FROM (SELECT * FROM (SELECT "school", COUNT(*) AS "students", (CASE WHEN COUNT(*) > COUNT("score") THEN NULL ELSE AVG(CAST("score" AS REAL)) END) AS "avg_score" FROM (SELECT * FROM (SELECT * FROM "schools") AS q1 WHERE ("study_hours" >= ?)) AS q2 GROUP BY "school") AS q3 WHERE ("students" >= ?)) AS q4 ORDER BY ("avg_score" IS NULL), "avg_score" DESC

# A tibble: 9 × 3
  school    students avg_score
  <chr>        <int>     <dbl>
1 school-07       14      86.9
2 school-06       20      80.9
3 school-02       12      79.7
4 school-11       13      77.6
5 school-10       13      77.5
6 school-01       12      76.6
7 school-05       13      75.8
8 school-04       20      71.1
9 school-08       12      67.5
```
