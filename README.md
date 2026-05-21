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
