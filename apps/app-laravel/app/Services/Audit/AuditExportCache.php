<?php

namespace App\Services\Audit;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/** Request-scoped disk cell cache keeps large spreadsheets out of PHP memory. */
final class AuditExportCache implements CacheInterface
{
    private readonly string $directory;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().'/audit-xlsx-'.bin2hex(random_bytes(12));
        if (! mkdir($this->directory, 0700)) {
            throw new \RuntimeException('Cannot create spreadsheet cache.');
        }
    }

    private function path(string $key): string
    {
        return $this->directory.'/'.hash('sha256', $key);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path($key);

        return is_file($path) ? unserialize(file_get_contents($path)) : $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        return file_put_contents($this->path($key), serialize($value), LOCK_EX) !== false;
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);

        return ! is_file($path) || unlink($path);
    }

    public function clear(): bool
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            if (! $this->set($key, $value, $ttl)) {
                return false;
            }
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            if (! $this->delete($key)) {
                return false;
            }
        }

        return true;
    }

    public function has(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function __destruct()
    {
        if (isset($this->directory)) {
            $this->clear();
            rmdir($this->directory);
        }
    }
}
