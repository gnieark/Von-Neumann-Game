# Von Neumann Game

Persistent PHP 8.2 prototype for a procedural Von Neumann probe game.

## Setup

Install Composer dependencies and generate the autoloader:

```bash
composer install
```

Create the local SQLite database:

```bash
php scripts/init-db.php
```

Create a first player and its initial probe:

```bash
php scripts/create-user.php remi secret "Remi"
```

Run the built-in PHP server:

```bash
php -S localhost:8000 -t public
```

Create a session:

```bash
curl -s -X POST http://localhost:8000/api/session \
  -H 'Content-Type: application/json' \
  -d '{"username":"remi","password":"secret"}'
```

Use the returned token:

```bash
curl -s http://localhost:8000/api/probe \
  -H "Authorization: Bearer <token>"
```

Read the current sector:

```bash
curl -s http://localhost:8000/api/probe/sector \
  -H "Authorization: Bearer <token>"
```

Initiate an asynchronous intersector movement with player-relative FCC
coordinates:

```bash
curl -s -X POST http://localhost:8000/api/probe/move \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer <token>" \
  -d '{"target":{"x":1,"y":1,"z":0}}'
```

Follow the movement. The server derives the current phase from timestamps, so no
cron task is required for movement progression:

```bash
curl -s http://localhost:8000/api/probe \
  -H "Authorization: Bearer <token>"
```

After arrival, consult the new current sector:

```bash
curl -s http://localhost:8000/api/probe/sector \
  -H "Authorization: Bearer <token>"
```

The current-sector response also includes the probe inventory. A new probe has
1 `earth_container_equivalent` of transport capacity and starts with:

- 1 atomic 3D printer, occupying 0.3 containers, with no current task
- 4 Mannies, each occupying 0.05 containers, with no current task

The probe uses nuclear fusion and also starts with a full external deuterium
tank. This special tank is mounted outside cargo storage, so it does not consume
the available container capacity. Each intersector movement currently consumes
2% of the probe's current deuterium stock.

Read the task state for one onboard object by using its inventory `id`:

```bash
curl -s http://localhost:8000/api/probe/inventory/probe-1-manny-1 \
  -H "Authorization: Bearer <token>"
```

Observe another sector with player-relative coordinates:

```bash
curl -s 'http://localhost:8000/api/sector?x=1&y=1&z=0' \
  -H "Authorization: Bearer <token>"
```

## Configuration

SQLite is configured in [config/database.json](config/database.json):

```json
{
  "driver": "sqlite",
  "path": "var/database.sqlite"
}
```

MariaDB can be configured with:

```json
{
  "driver": "mysql",
  "host": "localhost",
  "port": 3306,
  "database": "von_neumann_game",
  "username": "user",
  "password": "password",
  "charset": "utf8mb4"
}
```

## Tests

```bash
composer test
php class/Tests.php
```

The REST contract is documented in [docs/openapi.yaml](docs/openapi.yaml).
