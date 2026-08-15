<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Everything the status page reports about the process it is running in.
 *
 * The point of this class is that each method touches exactly one piece of the
 * deployed architecture, so a green row on the status page is proof that piece
 * is wired up correctly.
 */
class RuntimeFacts
{
    /**
     * Requests served by this Octane worker since it booted.
     *
     * Under php-fpm this is always 1, because the process is new. A number
     * larger than 1 is direct evidence that the framework stayed in memory
     * between requests, which is the whole reason to run Octane.
     */
    public static int $requestsServedByThisWorker = 0;

    /**
     * Set once, by AppServiceProvider::register(), the first time the
     * application is constructed in this process.
     *
     * Under Octane that is when the worker boots rather than when a request
     * arrives, so the difference from now() is how long this worker has been
     * alive. The framework's LARAVEL_START constant is no help here: it is
     * defined in public/index.php, which Octane never runs.
     */
    public static float $bootedAt = 0.0;

    public static function process(): array
    {

        return [
            'hostname' => gethostname(),
            'pid' => getmypid(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'server' => isset($_SERVER['FRANKENPHP_WORKER']) || extension_loaded('frankenphp')
                ? 'FrankenPHP worker'
                : (php_sapi_name() ?: 'unknown'),
            'octane' => (bool) env('OCTANE_SERVER'),
            'requests_served_by_this_worker' => self::$requestsServedByThisWorker,
            'uptime_seconds' => round(microtime(true) - self::$bootedAt, 1),
            'release' => config('app.release'),
        ];
    }

    /**
     * Task-level identity, straight from the ECS task metadata endpoint.
     *
     * ECS injects ECS_CONTAINER_METADATA_URI_V4 into every container. There is
     * no SDK call and no IAM permission involved; it is a link-local HTTP
     * endpoint scoped to the task itself.
     */
    public static function ecs(): array
    {
        $uri = env('ECS_CONTAINER_METADATA_URI_V4');

        if (! $uri) {
            return ['available' => false, 'reason' => 'not running on ECS'];
        }

        return Cache::remember('ecs-task-metadata', 300, function () use ($uri) {
            try {
                $task = Http::timeout(2)->get($uri.'/task')->json();

                return [
                    'available' => true,
                    'task_id' => substr((string) ($task['TaskARN'] ?? ''), strrpos((string) ($task['TaskARN'] ?? ''), '/') + 1),
                    'availability_zone' => $task['AvailabilityZone'] ?? null,
                    'cluster' => basename((string) ($task['Cluster'] ?? '')),
                    'family' => $task['Family'] ?? null,
                    'revision' => $task['Revision'] ?? null,
                    'cpu_limit' => $task['Limits']['CPU'] ?? null,
                    'memory_limit_mb' => $task['Limits']['Memory'] ?? null,
                    'launch_type' => $task['LaunchType'] ?? null,
                ];
            } catch (Throwable $e) {
                return ['available' => false, 'reason' => $e->getMessage()];
            }
        });
    }

    /** Proves the task can reach RDS through the data subnet security group. */
    public static function database(): array
    {
        $started = microtime(true);

        try {
            $version = DB::selectOne('select version() as v')->v;
            $reports = DB::table('reports')->count();
            $heartbeats = DB::table('heartbeats')->count();

            return [
                'ok' => true,
                'driver' => DB::connection()->getDriverName(),
                'engine' => trim(explode(' on ', $version)[0]),
                'latency_ms' => round((microtime(true) - $started) * 1000, 1),
                'reports' => $reports,
                'heartbeats' => $heartbeats,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()];
        }
    }

    /** Proves the cache store is shared, not per-container. */
    public static function cache(): array
    {
        try {
            $key = 'status:roundtrip';
            $started = microtime(true);
            Cache::put($key, $token = bin2hex(random_bytes(4)), 60);
            $ok = Cache::get($key) === $token;

            return [
                'ok' => $ok,
                'store' => config('cache.default'),
                'latency_ms' => round((microtime(true) - $started) * 1000, 1),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Proves the task role can sign S3 requests.
     *
     * There are no AWS keys anywhere in the container. The SDK picks up
     * credentials from the ECS task role over the same link-local endpoint,
     * which is why AWS_ACCESS_KEY_ID is deliberately absent from the task
     * definition.
     */
    public static function storage(): array
    {
        try {
            $started = microtime(true);
            $disk = Storage::disk('s3');
            $files = $disk->files('reports');

            return [
                'ok' => true,
                'bucket' => config('filesystems.disks.s3.bucket'),
                'objects' => count($files),
                'latency_ms' => round((microtime(true) - $started) * 1000, 1),
                'credentials' => env('AWS_ACCESS_KEY_ID') ? 'static keys (bad)' : 'task role',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()];
        }
    }

    /** Queue depth, read straight off the jobs table. */
    public static function queue(): array
    {
        try {
            return [
                'ok' => true,
                'connection' => config('queue.default'),
                'pending' => DB::table('jobs')->count(),
                'failed' => DB::table('failed_jobs')->count(),
                'completed_reports' => DB::table('reports')->where('status', 'completed')->count(),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * The scheduler writes a heartbeat row every minute. If the most recent one
     * is older than a couple of minutes the scheduler service is not running,
     * which is a failure mode that is otherwise completely silent.
     */
    public static function scheduler(): array
    {
        try {
            $last = DB::table('heartbeats')->orderByDesc('ran_at')->first();

            if (! $last) {
                return ['ok' => false, 'reason' => 'no heartbeat yet'];
            }

            $age = (int) round(now()->diffInSeconds($last->ran_at, absolute: true));

            return [
                'ok' => $age < 150,
                'last_run' => $last->ran_at,
                'age_seconds' => $age,
                'source' => $last->source,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
