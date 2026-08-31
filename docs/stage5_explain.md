# Stage 5: storefront query plans

Measured on 5012 products / 200050 keys (200038 free), postgres 16, `EXPLAIN (ANALYZE, BUFFERS)`.

## A. naive: aggregate the whole pool, then paginate

```sql
SELECT p.sku, p.name, p.price, COALESCE(a.available, 0) AS available
  FROM products p
  LEFT JOIN (
      SELECT sku, count(*) AS available
      FROM key_pool
      WHERE order_id IS NULL
      GROUP BY sku
  ) a ON a.sku = p.sku
  ORDER BY p.sku
  LIMIT 50 OFFSET 2000
```

```
Limit  (cost=2644.43..2710.52 rows=50 width=42) (actual time=12.579..12.899 rows=50 loops=1)
  Buffers: shared hit=712
  ->  Merge Left Join  (cost=0.58..6626.06 rows=5012 width=42) (actual time=0.039..12.848 rows=2050 loops=1)
        Merge Cond: (p.sku = key_pool.sku)
        Buffers: shared hit=712
        ->  Index Scan using products_pkey on products p  (cost=0.28..223.78 rows=5012 width=34) (actual time=0.007..0.164 rows=2050 loops=1)
              Buffers: shared hit=32
        ->  GroupAggregate  (cost=0.29..6277.19 rows=5003 width=19) (actual time=0.030..12.126 rows=2044 loops=1)
              Group Key: key_pool.sku
              Buffers: shared hit=680
              ->  Index Only Scan using key_pool_available_idx on key_pool  (cost=0.29..5226.94 rows=200043 width=11) (actual time=0.022..6.901 rows=81761 loops=1)
                    Heap Fetches: 81761
                    Buffers: shared hit=680
Planning:
  Buffers: shared hit=25
Planning Time: 0.182 ms
Execution Time: 12.944 ms
```

What you write when there is no counter. The GROUP BY has to touch every free key in the pool before a single row of the page can be returned, so its cost is set by the size of the inventory and not by the size of the page.

## B. correlated subquery per row of the page

```sql
SELECT p.sku, p.name, p.price,
         (SELECT count(*) FROM key_pool k WHERE k.sku = p.sku AND k.order_id IS NULL) AS available
  FROM products p
  ORDER BY p.sku
  LIMIT 50 OFFSET 2000
```

```
Limit  (cost=18299.47..18756.95 rows=50 width=42) (actual time=12.466..12.798 rows=50 loops=1)
  Buffers: shared hit=6778
  ->  Index Scan using products_pkey on products p  (cost=0.28..45858.04 rows=5012 width=42) (actual time=0.020..12.722 rows=2050 loops=1)
        Buffers: shared hit=6778
        SubPlan 1
          ->  Aggregate  (cost=9.10..9.11 rows=1 width=8) (actual time=0.006..0.006 rows=1 loops=2050)
                Buffers: shared hit=6746
                ->  Index Only Scan using key_pool_available_idx on key_pool k  (cost=0.29..9.00 rows=40 width=0) (actual time=0.001..0.004 rows=40 loops=2050)
                      Index Cond: (sku = p.sku)
                      Heap Fetches: 81760
                      Buffers: shared hit=6746
Planning Time: 0.056 ms
Execution Time: 12.829 ms
```

Much better for one plain page - only 50 index lookups. It degrades the moment the storefront wants to filter or sort BY availability, because then the count has to be evaluated for every product before anything can be discarded.

## C. materialised counter

```sql
SELECT p.sku, p.name, p.price, s.available
  FROM products p
  JOIN stock s ON s.sku = p.sku
  ORDER BY p.sku
  LIMIT 50 OFFSET 2000
```

```
Limit  (cost=248.71..254.91 rows=50 width=38) (actual time=1.114..1.138 rows=50 loops=1)
  Buffers: shared hit=2039
  ->  Merge Join  (cost=0.56..622.41 rows=5012 width=38) (actual time=0.015..1.090 rows=2050 loops=1)
        Merge Cond: (p.sku = s.sku)
        Buffers: shared hit=2039
        ->  Index Scan using products_pkey on products p  (cost=0.28..223.78 rows=5012 width=34) (actual time=0.007..0.168 rows=2050 loops=1)
              Buffers: shared hit=32
        ->  Index Scan using stock_pkey on stock s  (cost=0.28..323.46 rows=5012 width=15) (actual time=0.006..0.416 rows=2050 loops=1)
              Buffers: shared hit=2007
Planning:
  Buffers: shared hit=64
Planning Time: 0.386 ms
Execution Time: 1.158 ms
```

The page is read from an index-ordered join of two small tables. Cost depends on the page, not on the inventory, and it stays flat as the pool grows.

## D. materialised counter, in-stock filter

```sql
SELECT p.sku, p.name, p.price, s.available
  FROM products p
  JOIN stock s ON s.sku = p.sku
  WHERE s.available > 0
  ORDER BY p.sku
  LIMIT 50 OFFSET 2000
```

```
Limit  (cost=245.97..252.11 rows=50 width=38) (actual time=0.931..0.952 rows=50 loops=1)
  Buffers: shared hit=2031
  ->  Merge Join  (cost=0.56..614.08 rows=5000 width=38) (actual time=0.014..0.908 rows=2050 loops=1)
        Merge Cond: (p.sku = s.sku)
        Buffers: shared hit=2031
        ->  Index Scan using products_pkey on products p  (cost=0.28..223.78 rows=5012 width=34) (actual time=0.005..0.130 rows=2056 loops=1)
              Buffers: shared hit=32
        ->  Index Scan using stock_available_idx on stock s  (cost=0.28..315.28 rows=5000 width=15) (actual time=0.006..0.313 rows=2050 loops=1)
              Buffers: shared hit=1999
Planning:
  Buffers: shared hit=14
Planning Time: 0.127 ms
Execution Time: 0.968 ms
```

The case that decides it: the filter is a column, so stock_available_idx answers it directly. Variant A would have to aggregate the entire pool first.

## Why it is built this way

- **A. naive: aggregate the whole pool, then paginate** - 12.944 ms
- **B. correlated subquery per row of the page** - 12.829 ms
- **C. materialised counter** - 1.158 ms
- **D. materialised counter, in-stock filter** - 0.968 ms

1. The partial index `key_pool (sku) WHERE order_id IS NULL` is what makes reserving a key cheap and what keeps the free-key count off the dead rows, and it is enough for a single lookup by sku (variant B). It is not enough for the storefront, because an index cannot return a count without walking every matching entry - the work still scales with inventory.
2. So availability is materialised into `stock` and decremented inside the very transaction that reserves the key (`suppliers/supplier.php`). There is no window in which a key is gone from the pool but still advertised.
3. That turns the hot query into a join of two small, index-ordered tables: cost tracks the page size, not the number of keys, and filtering by availability becomes an indexed column predicate instead of a post-aggregation filter.
4. A materialised counter can drift, so `bin/reconcile.php` compares every `stock.available` against the real pool and reports any sku where the two disagree.

