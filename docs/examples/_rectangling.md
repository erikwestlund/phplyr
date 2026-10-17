```php
use function Phplyr\chop;

$people = DataFrame::fromColumns([
    'id' => [1, 2, 3],
    'info' => [
        ['name' => 'ann', 'langs' => ['php', 'r'], 'city' => ['name' => 'Oslo']],
        ['name' => 'bob', 'langs' => ['sql']],
        null,
    ],
]);

echo $people |> hoist('info', town: ['city', 'name']), "\n";

echo $people |> unnest_wider('info') |> select('id', 'langs') |> unnest_longer('langs', indices_include: true), "\n";

echo DataFrame::fromColumns(['g' => ['a', 'a', 'b'], 'x' => [1, 2, 3]]) |> chop('x');
```

```{.text .output}
# A tibble: 3 × 3
     id town  info            
  <int> <chr> <list>          
1     1 Oslo  <named list [3]>
2     2 <NA>  <named list [2]>
3     3 <NA>  <NULL>          
# A tibble: 3 × 3
     id langs_id langs
  <int>    <int> <chr>
1     1        1 php  
2     1        2 r    
3     2        1 sql  
# A tibble: 2 × 2
  g     x        
  <chr> <list>   
1 a     <int [2]>
2 b     <int [1]>
```
