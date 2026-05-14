<?php

namespace App\Services;

use App\Jobs\ProcessGradeJob;
use App\Models\Grade;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class GradeService
{
    private const CACHE_TAG = 'grades';

    private const CACHE_TTL = 60;

    public function list(): AbstractPaginator
    {
        $key = 'grades:index:'.md5(serialize(request()->all(['page', 'filter', 'sort', 'fields'])));

        return $this->cache()->remember(
            $key,
            self::CACHE_TTL,
            fn () => QueryBuilder::for(Grade::class)
                ->allowedFilters(
                    AllowedFilter::partial('name'),
                    AllowedFilter::exact('subject'),
                    AllowedFilter::callback('min_score', fn ($q, $v) => $q->where('score', '>=', (int) $v)),
                    AllowedFilter::callback('max_score', fn ($q, $v) => $q->where('score', '<=', (int) $v)),
                    AllowedFilter::exact('horizon_processed'),
                )
                ->allowedSorts('score', 'name', 'subject', 'created_at')
                ->defaultSort('-created_at')
                ->jsonPaginate()
        );
    }

    public function find(string $id): Grade
    {
        return $this->cache()->remember(
            "grades:show:{$id}",
            self::CACHE_TTL,
            fn () => Grade::query()->findOrFail($id)
        );
    }

    /**
     * @param  array{name: string, subject: string, score: int}  $data
     */
    public function create(array $data): Grade
    {
        $grade = DB::transaction(fn () => Grade::query()->create($data));

        ProcessGradeJob::dispatch($grade->id);

        $this->flushCache();

        return $grade;
    }

    public function delete(string $id): void
    {
        $grade = Grade::query()->findOrFail($id);

        DB::transaction(fn () => $grade->delete());

        $this->flushCache();
    }

    public function flushCache(): void
    {
        $this->cache()->flush();
    }

    private function cache()
    {
        return Cache::supportsTags()
            ? Cache::tags([self::CACHE_TAG])
            : Cache::store();
    }
}
