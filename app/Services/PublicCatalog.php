<?php

namespace App\Services;

use App\Models\JobCategory;
use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PublicCatalog
{
    /** @return Collection<int, JobCategory> */
    public function categories(): Collection
    {
        $model = new JobCategory;
        $rows = Cache::remember($this->key($model), 300, fn (): array => $model->newQuery()->orderBy('name')->get(['id', 'name'])->toArray());

        return JobCategory::hydrate($rows);
    }

    /** @return Collection<int, Location> */
    public function locations(): Collection
    {
        $model = new Location;
        $rows = Cache::remember($this->key($model), 300, fn (): array => $model->newQuery()->orderBy('name')->get(['id', 'code', 'name'])->toArray());

        return Location::hydrate($rows);
    }

    public function invalidateAfterCommit(Model $model): void
    {
        $key = $this->key($model);
        $model->getConnection()->afterCommit(static fn (): bool => Cache::forget($key));
    }

    private function key(Model $model): string
    {
        $connection = $model->getConnection();
        $source = [$connection->getName(), config('database.connections.'.$connection->getName().'.url'),
            $connection->getConfig('driver'), $connection->getConfig('host'), $connection->getConfig('port'),
            $connection->getDatabaseName(), $connection->getConfig('username'), $connection->getConfig('search_path')];

        return 'public-catalog:v1:'.hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)).':'.$model->getTable();
    }
}
