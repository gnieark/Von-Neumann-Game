# Von Neumann Game

Persistent PHP 8 prototype for a procedural Von Neumann probe game.

## Development status: 

ongoing

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

Optional OAuth sign-in uses Google and Discord OpenID. Copy the example file,
fill your provider client IDs and secrets, and register these callback URLs in
the provider consoles:

```bash
cp config/oauth.example.json config/oauth.json
```

```text
http://localhost:8000/auth/provider/google
http://localhost:8000/auth/provider/discord
```

Only the provider name and OpenID `sub` are stored. On first sign-in, the player
chooses a pseudonym; the account and initial Von Neumann probe are then created.

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
- 4 persisted Mannies, named `manny-1` through `manny-4`, each occupying
  0.05 containers while onboard

The probe uses nuclear fusion and also starts with a full external deuterium
tank. This special tank is mounted outside cargo storage, so it does not consume
the available container capacity. Each intersector movement currently consumes
2% of the probe's current deuterium stock and adds 0 to 3% hull damage per
traversed sector.

List Manny robots and use their generated `id` for orders:

```bash
curl -s http://localhost:8000/api/probe/mannies \
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
