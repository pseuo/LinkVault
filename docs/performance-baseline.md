# Local SQLite performance baseline

Collected on 2026-08-10 with PHP 8.5.9, SQLite PDO, a 32 MiB per-connection page cache,
an Intel Core i5-4590S (4 cores), 8 GB RAM, and local Windows storage. These are in-process
service/query timings, not Nginx/PHP-FPM capacity results. Each operation had one warm-up.

| Links | Analytics rows | DB size | Operation | P50 | P95 | P99 |
|---:|---:|---:|---|---:|---:|---:|
| 10,000 | 10,000 | 5.45 MiB | Redirect lookup | 0.006 ms | 0.009 ms | 0.009 ms |
| 10,000 | 10,000 | 5.45 MiB | Admin first page | 1.347 ms | 1.454 ms | 1.454 ms |
| 10,000 | 10,000 | 5.45 MiB | Admin last page | 4.863 ms | 5.169 ms | 5.169 ms |
| 10,000 | 10,000 | 5.45 MiB | Search | 6.021 ms | 6.427 ms | 6.427 ms |
| 10,000 | 10,000 | 5.45 MiB | Analytics report | 337.390 ms | 346.898 ms | 346.898 ms |
| 10,000 | 10,000 | 5.45 MiB | Filtered CSV | 3.112 ms | 3.115 ms | 3.115 ms |
| 100,000 | 100,000 | 70.20 MiB | Redirect lookup | 0.007 ms | 0.011 ms | 0.011 ms |
| 100,000 | 100,000 | 71.00 MiB | Admin first page | 5.352 ms | 5.900 ms | 5.900 ms |
| 100,000 | 100,000 | 71.00 MiB | Admin last page | 6.178 ms | 6.868 ms | 6.868 ms |
| 100,000 | 100,000 | 71.00 MiB | Search | 47.137 ms | 49.613 ms | 49.613 ms |
| 100,000 | 100,000 | 71.00 MiB | Analytics report (cold) | 2125.610 ms | 2133.487 ms | 2133.487 ms |
| 100,000 | 100,000 | 71.00 MiB | Analytics report cache hit | 0.314 ms | 0.315 ms | 0.315 ms |
| 100,000 | 100,000 | 71.00 MiB | Analytics filter options | 189.024 ms | 190.159 ms | 190.159 ms |
| 100,000 | 100,000 | 71.00 MiB | Filtered CSV | 27.540 ms | 27.564 ms | 27.564 ms |
| 1,000,000 | 100,000 | 416.25 MiB | Redirect lookup | 0.007 ms | 0.022 ms | 0.022 ms |
| 1,000,000 | 100,000 | 416.25 MiB | Admin first page | 48.715 ms | 49.001 ms | 49.001 ms |
| 1,000,000 | 100,000 | 416.25 MiB | Admin last page | 50.072 ms | 51.012 ms | 51.012 ms |
| 1,000,000 | 100,000 | 416.25 MiB | Search | 247.996 ms | 252.502 ms | 252.502 ms |
| 1,000,000 | 100,000 | 416.25 MiB | Analytics report (cold) | 3284.517 ms | 3314.827 ms | 3314.827 ms |
| 1,000,000 | 100,000 | 416.25 MiB | Analytics report cache hit | 0.304 ms | 0.381 ms | 0.381 ms |
| 1,000,000 | 100,000 | 416.25 MiB | Analytics filter options | 324.257 ms | 324.773 ms | 324.773 ms |
| 1,000,000 | 100,000 | 416.25 MiB | Filtered CSV | 263.695 ms | 265.499 ms | 265.499 ms |

The 100,000-link run initially exhausted the 128 MiB PHP memory limit in analytics rankings.
Streaming ranking inputs, retaining only each top-eight result, and materializing all-time bounds
once per request removed that failure. Redirect lookups remain effectively flat. Reverse deep
pagination makes the last page comparable to the first page. Analytics remains the dominant
workload because the report intentionally computes many independent dimensions; this baseline
does not justify replacing SQLite for the current single-host model.

The current-range materialization threshold was 250,000 rows. A separate 100,000-link Rollup-ready
run produced a 1,970.350 ms cold P95 and a 0.311 ms cache-hit P95 while preserving totals. Production
benefit depends on how many hourly rows collapse into each daily dimensional row.
