<?php

namespace App\Jobs;

use App\Models\Grade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ProcessGradeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $gradeId) {}

    public function handle(): void
    {
        $grade = Grade::query()->find($this->gradeId);

        if (! $grade) {
            return;
        }

        sleep(1);

        $grade->update(['horizon_processed' => true]);

        if (Cache::supportsTags()) {
            Cache::tags(['grades'])->flush();
        }
    }
}
