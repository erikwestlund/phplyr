```php
use function Phplyr\{floor_date, str_detect, str_extract, str_replace_all, str_to_title, ymd};

$notes = Frame::fromRows([
    ['when' => '2024-01-31', 'note' => 'paid INV-1042 late'],
    ['when' => '2024-02-29', 'note' => 'refund inv-77'],
    ['when' => '2024-03-15', 'note' => 'no invoice'],
]);

// Dates: parse, round, shift by a month (an invalid date gives NA), weekday.
echo $notes
    |> mutate(
        date: ymd('when'),
        month_start: floor_date(ymd('when'), 'month'),
        next_month: ymd('when')->add(months(1)),
        weekday: wday(ymd('when'), week_start: 1),
    )
    |> select(except('note')), "\n";

// Strings: patterns are regular expressions unless wrapped in fixed().
echo $notes
    |> mutate(
        invoice: str_extract('note', regex('inv-\d+', ignore_case: true)),
        literal: str_detect('note', fixed('INV')),
        masked: str_replace_all('note', '\d', '#'),
        title: str_to_title('note'),
    )
    |> select(except('when', 'note'));
```

```{.text .output}
# A tibble: 3 × 5
  when       date       month_start next_month weekday
  <chr>      <date>     <date>      <date>       <int>
1 2024-01-31 2024-01-31 2024-01-01  NA               3
2 2024-02-29 2024-02-29 2024-02-01  2024-03-29       4
3 2024-03-15 2024-03-15 2024-03-01  2024-04-15       5
# A tibble: 3 × 4
  invoice  literal masked             title             
  <chr>    <lgl>   <chr>              <chr>             
1 INV-1042 TRUE    paid INV-#### late Paid Inv-1042 Late
2 inv-77   FALSE   refund inv-##      Refund Inv-77     
3 <NA>     FALSE   no invoice         No Invoice
```
