<?php

namespace OzanKurt\Shield\Services\Lookups;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class LookupResolver
{
    private array $cache = [];

    public function id(string $modelClass, string $name): ?int
    {
        $map = $this->table($modelClass);
        return $map[$name] ?? null;
    }

    public function name(string $modelClass, int $id): ?string
    {
        return array_search($id, $this->table($modelClass), true) ?: null;
    }

    public function all(string $modelClass): array
    {
        return $this->table($modelClass);
    }

    public function flush(?string $modelClass = null): void
    {
        if ($modelClass) {
            unset($this->cache[$modelClass]);
            Cache::forget($this->cacheKey($modelClass));
        } else {
            $this->cache = [];
            // Note: clearing all lookup caches requires knowing all classes;
            // callers tracking lookups should iterate. For initial install,
            // flushing via `php artisan cache:clear` covers it.
        }
    }

    /** @return array<string, int> name → id */
    private function table(string $modelClass): array
    {
        if (isset($this->cache[$modelClass])) {
            return $this->cache[$modelClass];
        }

        $this->cache[$modelClass] = Cache::rememberForever(
            $this->cacheKey($modelClass),
            fn () => $this->load($modelClass),
        );

        return $this->cache[$modelClass];
    }

    private function load(string $modelClass): array
    {
        /** @var Model $modelClass */
        return $modelClass::query()->pluck('id', 'name')->all();
    }

    private function cacheKey(string $modelClass): string
    {
        return 'shield.lookups.' . class_basename($modelClass);
    }
}
