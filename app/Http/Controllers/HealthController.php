<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    /**
     * Readiness probe: pod is in-rotation only when DB + Redis respond.
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];

        $ok = collect($checks)->every(fn (array $c): bool => $c['ok']);

        if (! $ok) {
            Log::warning('readiness failed', $checks);
        }

        return response()->json([
            'status' => $ok ? 'ready' : 'not_ready',
            'checks' => $checks,
        ], $ok ? 200 : 503);
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1');

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private function checkRedis(): array
    {
        try {
            $response = Redis::connection()->ping();
            $normalized = $response === true ? 'PONG' : ltrim((string) $response, '+');
            $ok = $normalized === 'PONG';

            return $ok ? ['ok' => true] : ['ok' => false, 'error' => "unexpected ping response: {$normalized}"];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
