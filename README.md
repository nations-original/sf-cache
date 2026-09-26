# sf-cache

PSR-16 cache adapters for Redis, Memcached, APCu, and the filesystem.
Works standalone, with Symfony, with legacy projects, and with any PSR-16-aware framework.

## Installation

Via Packagist:
```bash
composer require nations-original/sf-cache
```

## Adapters

| Adapter    | Helper  | Class                    | Requirement                         | Limitations                                      |
|------------|---------|--------------------------|-------------------------------------|--------------------------------------------------|
| Redis      | `rca()` | `RedisCacheAdapter`      | `predis/predis`, running Redis      | —                                                |
| APCu       | `aca()` | `APCuCacheAdapter`       | `ext-apcu`                          | —                                                |
| Memcached  | `mca()` | `MemcachedCacheAdapter`  | `ext-memcached`, running Memcached  | `deleteByKeyPattern()` throws `UnsupportedPlatformException` — Memcached has no key-enumeration protocol |
| FileSystem | `fca()` | `FileSystemCacheAdapter` | `symfony/filesystem` (included)     | —                                                |
| Auto       | `ca()`  | —                        | Picks APCu if available, else Redis | Inherits the limitations of the selected adapter |

All adapters implement `PHP_SF\Cache\CacheInterface` which extends `Psr\SimpleCache\CacheInterface` (PSR-16).

---

## Setup by environment

### Symfony

**1. Wire the connections in `services.yaml`:**

```yaml
PHP_SF\Cache\Connection\Redis:
  arguments:
    - '%env(REDIS_CACHE_URL)%'
    - '%env(SERVER_PREFIX)%:%env(APP_ENV)%:'

PHP_SF\Cache\Adapter\RedisCacheAdapter:
  arguments:
    - '@PHP_SF\Cache\Connection\Redis'

PHP_SF\Cache\Connection\Memcached:
  arguments:
    - '%env(MEMCACHED_SERVER)%'
    - '%env(int:MEMCACHED_PORT)%'
    - '%env(SERVER_PREFIX)%:%env(APP_ENV)%:'

PHP_SF\Cache\Adapter\MemcachedCacheAdapter:
  arguments:
    - '@PHP_SF\Cache\Connection\Memcached'

PHP_SF\Cache\Adapter\FileSystemCacheAdapter:
  arguments:
    $filesystem: '@filesystem'
    $cacheDir:   '%kernel.cache_dir%/php_sf_cache'
```

Injecting the connection into the adapter gives the container a proper dependency edge: it guarantees the connection is instantiated (and therefore configured) before the adapter is first used.

**2. Use via PSR-16 (type-hint `Psr\SimpleCache\CacheInterface`):**

```php
use Psr\SimpleCache\CacheInterface;

class MyService
{
    public function __construct(private CacheInterface $cache) {}
}
```

Bind in `services.yaml`:
```yaml
Psr\SimpleCache\CacheInterface: '@PHP_SF\Cache\Adapter\RedisCacheAdapter'
```

**3. Or wrap as PSR-6 (for Symfony cache pools):**

```php
use Symfony\Component\Cache\Psr16Cache;
use PHP_SF\Cache\Adapter\RedisCacheAdapter;

$pool = new Psr16Cache(RedisCacheAdapter::getInstance());
```

---

### Laravel

The service provider is auto-discovered via `composer.json`. No manual registration needed.

It reads connection details from Laravel's existing config so you don't need extra env vars:

| Adapter    | Laravel config source                                             |
|------------|-------------------------------------------------------------------|
| Redis      | `config/database.php` → `redis.cache` (fallback: `redis.default`) |
| Memcached  | `config/cache.php` → `stores.memcached.servers[0]`                |
| FileSystem | `storage/framework/cache/php_sf_cache`                            |

The key prefix is built as `{APP_NAME}:{APP_ENV}:` automatically.

By default `PHP_SF\Cache\CacheInterface` is bound to the auto adapter (`ca()`: APCu if available, else Redis). Override in `AppServiceProvider` if you need a specific one:

```php
use PHP_SF\Cache\CacheInterface;
use PHP_SF\Cache\Adapter\RedisCacheAdapter;

public function register(): void
{
    $this->app->singleton(CacheInterface::class, fn() => rca());
}
```

Or type-hint `PHP_SF\Cache\CacheInterface` directly in your classes and let the container inject it.

---

### Custom framework (SF or similar)

Set env vars before the first cache call (e.g. in your bootstrap or `.env`):

```
REDIS_CACHE_URL=redis://localhost:6379/0
SERVER_PREFIX=myapp
APP_ENV=prod

MEMCACHED_SERVER=localhost
MEMCACHED_PORT=11211

CACHE_DIR=/var/www/myapp/var/cache/sf_cache
```

Then call helpers anywhere:

```php
rca()->set('user:42', $userData, 3600);
$user = rca()->get('user:42');

fca()->set('report', $heavyObject);   // filesystem, survives process restarts
ca()->set('flag', true);              // auto: APCu if available, else Redis
```

Or configure explicitly in bootstrap:

```php
use PHP_SF\Cache\Connection\Redis;
use PHP_SF\Cache\Adapter\FileSystemCacheAdapter;
use Symfony\Component\Filesystem\Filesystem;

Redis::configure('redis://localhost:6379/0', 'myapp:prod:');
FileSystemCacheAdapter::configure(new Filesystem(), '/var/cache/myapp');
```

---

### Legacy projects (no framework)

Composer autoload takes care of the function stubs. Just set `$_ENV` or call `configure()`:

```php
require 'vendor/autoload.php';

$_ENV['REDIS_CACHE_URL'] = 'redis://127.0.0.1:6379/0';

rca()->set('greeting', 'hello', 60);
echo rca()->get('greeting'); // hello
```

---

### Custom implementation

Extend `AbstractCacheAdapter` and implement `CacheInterface`:

```php
use PHP_SF\Cache\Abstracts\AbstractCacheAdapter;

final class MyCustomAdapter extends AbstractCacheAdapter
{
    public function get(string $key, mixed $default = null): mixed { /* ... */ }
    public function set(string $key, mixed $value, $ttl = null): bool { /* ... */ }
    public function delete(string $key): bool { /* ... */ }
    public function clear(): bool { /* ... */ }
    public function has(string $key): bool { /* ... */ }
    public function deleteByKeyPattern(string $keyPattern): bool { /* ... */ }
}
```

Or implement `PHP_SF\Cache\CacheInterface` directly without the abstract base.

---

## API reference

### All adapters (PSR-16 + pattern deletion)

```php
$cache->get(string $key, mixed $default = null): mixed
$cache->set(string $key, mixed $value, int|DateInterval|null $ttl = 86400): bool  // null = no expiry
$cache->delete(string $key): bool
$cache->clear(): bool
$cache->has(string $key): bool
$cache->getMultiple(iterable $keys, mixed $default = null): iterable
$cache->setMultiple(iterable $values, int|DateInterval|null $ttl = 86400): bool   // null = no expiry
$cache->deleteMultiple(iterable $keys): bool

// Pattern wildcards: prefix*, *suffix, *contains*, *
// Returns true when no keys match (nothing to delete = success)
$cache->deleteByKeyPattern(string $keyPattern): bool
```

### Redis extras

```php
rca()->pub(string $channel, mixed $message): bool  // pub/sub publish

rc()  // raw Predis\Client
rp()  // Predis\Pipeline
```

### Adapter-specific notes

**Redis** — all values are stored as strings by Predis. Integers, floats, and booleans round-trip as strings. Cast explicitly on retrieval:
```php
$count   = (int)   rca()->get('hits');
$ratio   = (float) rca()->get('ratio');
$enabled = (bool)  rca()->get('feature:enabled');   // '' = false, '1' = true
```

**FileSystem** — accepts scalar, array, and object values (anything PHP can serialize). `deleteByKeyPattern()` reads all cache files to match original keys — fine for moderate cache sizes, not recommended for millions of entries. Survives process restarts; ideal for heavy computed objects.

**Memcached** — `deleteByKeyPattern()` is not supported and always throws `UnsupportedPlatformException`. The Memcached protocol provides no way to enumerate keys, so pattern-based deletion cannot be implemented. Use Redis or FileSystem if you need this feature.

---

## Connection configuration reference

### Redis

| Method                                               | Description                             |
|------------------------------------------------------|-----------------------------------------|
| `Redis::configure(string $url, string $prefix = '')` | Explicit config. Call before first use. |
| `$_ENV['REDIS_CACHE_URL']`                           | e.g. `redis://localhost:6379/0`         |
| `$_ENV['SERVER_PREFIX']` + `$_ENV['APP_ENV']`        | Builds key prefix `{prefix}:{env}:`     |

### Memcached

| Method                                                                 | Description       |
|------------------------------------------------------------------------|-------------------|
| `Memcached::configure(string $server, int $port, string $prefix = '')` | Explicit config.  |
| `$_ENV['MEMCACHED_SERVER']`                                            | e.g. `localhost`  |
| `$_ENV['MEMCACHED_PORT']`                                              | e.g. `11211`      |
| `$_ENV['SERVER_PREFIX']` + `$_ENV['APP_ENV']`                          | Builds key prefix |

### FileSystemCacheAdapter

| Method                                                                | Description                       |
|-----------------------------------------------------------------------|-----------------------------------|
| `FileSystemCacheAdapter::configure(Filesystem $fs, string $cacheDir)` | Explicit config.                  |
| `$_ENV['CACHE_DIR']`                                                  | Directory path                    |
| *(default)*                                                           | `sys_get_temp_dir()/sf_cache` |

---

## License

ISC — see [LICENSE](LICENSE).
