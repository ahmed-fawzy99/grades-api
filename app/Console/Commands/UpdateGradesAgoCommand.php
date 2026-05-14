<?php

namespace App\Console\Commands;

use App\Models\Grade;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('grades:update-ago')]
#[Description('Sets the "ago" field on the last 5 grades to a human-readable created_at diff.')]
class UpdateGradesAgoCommand extends Command
{
    public function handle(): int
    {
        $grades = Grade::query()->latest()->take(5)->get();

        foreach ($grades as $grade) {
            $grade->ago = $grade->created_at?->diffForHumans();
            $grade->save();
        }

        if (Cache::supportsTags()) {
            Cache::tags(['grades'])->flush();
        }

        $this->info("Updated {$grades->count()} grades.");

        return self::SUCCESS;
    }
}
