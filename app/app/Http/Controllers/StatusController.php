<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReport;
use App\Models\Report;
use App\Support\RuntimeFacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class StatusController extends Controller
{
    public function index(): View
    {
        RuntimeFacts::$requestsServedByThisWorker++;

        return view('status', [
            'process' => RuntimeFacts::process(),
            'ecs' => RuntimeFacts::ecs(),
            'database' => RuntimeFacts::database(),
            'cache' => RuntimeFacts::cache(),
            'storage' => RuntimeFacts::storage(),
            'queue' => RuntimeFacts::queue(),
            'scheduler' => RuntimeFacts::scheduler(),
            'reports' => Report::latest()->limit(10)->get(),
        ]);
    }

    /** Machine-readable version of the same thing, for the deploy scripts. */
    public function json(): array
    {
        RuntimeFacts::$requestsServedByThisWorker++;

        return [
            'process' => RuntimeFacts::process(),
            'ecs' => RuntimeFacts::ecs(),
            'database' => RuntimeFacts::database(),
            'cache' => RuntimeFacts::cache(),
            'storage' => RuntimeFacts::storage(),
            'queue' => RuntimeFacts::queue(),
            'scheduler' => RuntimeFacts::scheduler(),
        ];
    }

    public function dispatchReport(Request $request): RedirectResponse
    {
        $report = Report::create([
            'title' => $request->string('title')->value() ?: 'Usage report '.now()->format('H:i:s'),
            'status' => 'queued',
        ]);

        GenerateReport::dispatch($report->id);

        return redirect('/')->with('flash', "Queued report #{$report->id}. The worker service picks it up.");
    }

    /** Signed URL so the object itself never needs to be public. */
    public function downloadReport(Report $report): RedirectResponse
    {
        abort_unless($report->s3_key, 404);

        return redirect(Storage::disk('s3')->temporaryUrl($report->s3_key, now()->addMinutes(5)));
    }
}
