# avisitor/dbbase

Lightweight PDO wrapper and CRUD base class extracted from retreehawaii.
Namespace: `Common\DB`.

Features:
- `DBBase` — small typed wrapper over PDO for query/execute plus simple CRUD helpers.
- Optional default connection, so instances don't need a PDO passed in.
- `DBConnection` — builds a PDO from environment variables or a PHP config file,
  with a `RETREE_TEST_DB` override for tests.

## Install

```json
{
  "require": { "avisitor/dbbase": "^1.0" },
  "repositories": [
    { "type": "vcs", "url": "https://github.com/avisitor/dbbase.git" }
  ]
}
```

## Usage

```php
use Common\DB\DBConnection;
use Common\DB\DBBase;

$pdo = DBConnection::fromEnv();
DBBase::setDefaultConnection($pdo);

class DBThing extends DBBase {
    public function __construct(...$args) {
        parent::__construct(...$args);
        $this->table = 'thing';
        $this->prefix = 'thg';
    }
}

$rows = (new DBThing())->getAll();
```

## Configuration

- `DBConnection::fromEnv(array $overrides = [])` reads `DB_HOST`, `DB_USER`,
  `DB_PASS`, `DB_NAME`, `DB_PORT`, `DB_SOCKET`, and uses `RETREE_TEST_DB` to
  override the database name.
- `DBConnection::fromConfigFile(string $path, ?string $dbNameOverride = null)`
  reads a PHP array returned by the file with keys `db_host`, `db_user`,
  `db_pass`, `db_name` (and optional `db_port`, `db_socket`), and also honours
  `RETREE_TEST_DB`.

## Tests

```bash
./tests/run-tests.sh
```

## Scope

This library intentionally contains only generic helpers. App-specific mapping
belongs in a base class in the app that extends `DBBase`.
