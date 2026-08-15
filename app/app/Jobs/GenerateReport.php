<?php

namespace App\Jobs;

use App\Models\Report;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Deliberately does the two things a real background job does: takes long
 * enough that you would never do it in a request, and writes to object storage.
 *
 * If this job completes, three things are proven at once: the worker service is
 * running, it can reach the database, and its task role can write to S3.
 */
class GenerateReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $reportId) {}

    public function handle(): void
    {
        $report = Report::findOrFail($this->reportId);

        $rows = [['id', 'metric', 'value', 'generated_at']];

        for ($i = 1; $i <= 500; $i++) {
            $rows[] = [$i, 'requests_per_minute', random_int(80, 400), now()->toIso8601String()];
        }

        $csv = implode("\n", array_map(fn ($r) => implode(',', $r), $rows));
        $key = "reports/{$report->id}-".str()->slug($report->title).'.csv';

        Storage::disk('s3')->put($key, $csv);

        $report->update([
            'status' => 'completed',
            's3_key' => $key,
            'rows' => count($rows) - 1,
            'completed_at' => now(),
            'worker' => gethostname(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Report::where('id', $this->reportId)->update([
            'status' => 'failed',
            'worker' => gethostname(),
        ]);
    }
}
