# retree/dbbase

Lightweight generic DB base class (PDO) extracted from retreehawaii.

Features:
- Small typed wrapper over PDO for query/execute and simple CRUD helpers.
- Optional global default connection so you don't have to pass a PDO to each instance.
- `DBConnection` helper to build PDO from environment or a config file (with test DB override via `RETREE_TEST_DB`).

Install (local path):

1) Add to your composer.json

```json
{
  "require": { "retree/dbbase": "*@dev" },
  "repositories": [ { "type": "path", "url": "../php-libs/dbbase", "options": { "symlink": true } } ]
}
```

2) composer update

Usage:

```php
use Retree\DB\DBConnection;
use Retree\DB\DBBase;

$pdo = DBConnection::fromEnv();
DBBase::setDefaultConnection($pdo);

class DBThing extends DBBase {
  public function __construct(...$args){ parent::__construct(...$args); $this->table='thing'; $this->prefix='thg'; }
}

$t = new DBThing();
$rows = $t->getAll();
```

Config resolution:
- `DBConnection::fromEnv()` reads DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, DB_SOCKET, and `RETREE_TEST_DB` to override the db name.
- `DBConnection::fromConfigFile($path, $dbNameOverride)` reads a PHP array config `[db_host, db_user, db_pass, db_name]` and respects `RETREE_TEST_DB` or explicit override.

Notes:
- This library intentionally contains only generic helpers. App-specific mapping or behaviors should live in your app’s own base class that composes or extends this.
