# Redis API rate limiting

The API rate limiter reads its connection and sliding-window settings from
`config/redis.json`. A local override can be placed in
`config/redis-local.json` or `config/redis.local.json`.

The Redis instance used only for rate limiting does not need persistence. Its
server configuration should contain:

```conf
save ""
appendonly no
```

Redis persistence is configured for the whole Redis instance, not per logical
database. Do not apply these settings to a shared production instance that
stores durable data; use a dedicated Redis instance instead.

The application requires the Debian `php-redis` package. Redis failure is
fail-open: authenticated API requests continue without rate limiting and the
failure is written to the PHP error log.

Each accepted request is kept in a sorted set for the configured sliding
window. Keys contain a SHA-256 hash of the bearer token, never the clear token,
and expire automatically after inactivity.
