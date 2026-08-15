<!DOCTYPE html>
<html lang="en" class="bg-slate-950">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} runtime status</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-200 font-sans antialiased">

@php
    $pill = fn ($ok) => $ok
        ? 'bg-emerald-500/10 text-emerald-300 ring-emerald-500/30'
        : 'bg-rose-500/10 text-rose-300 ring-rose-500/30';
@endphp

<div class="mx-auto max-w-5xl px-6 py-12">

    <header class="flex items-start justify-between gap-6 border-b border-white/10 pb-6">
        <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Laravel on ECS Fargate</p>
            <h1 class="mt-1 text-2xl font-semibold text-white">Runtime status</h1>
            <p class="mt-1 text-sm text-slate-400">
                Every row below is a live call against one piece of the deployed stack.
            </p>
        </div>
        <div class="shrink-0 rounded-lg bg-white/5 px-4 py-3 text-right ring-1 ring-white/10">
            <p class="font-mono text-xs text-slate-400">release</p>
            <p class="font-mono text-sm text-white">{{ $process['release'] ?: 'dev' }}</p>
        </div>
    </header>

    @if (session('flash'))
        <div class="mt-6 rounded-lg bg-sky-500/10 px-4 py-3 text-sm text-sky-200 ring-1 ring-sky-500/30">
            {{ session('flash') }}
        </div>
    @endif

    {{-- ---------- process ---------- --}}
    <section class="mt-8">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">This process</h2>
        <dl class="mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-white/10 ring-1 ring-white/10 sm:grid-cols-4">
            @foreach ([
                'PHP' => $process['php'],
                'Laravel' => $process['laravel'],
                'SAPI' => $process['server'],
                'PID' => $process['pid'],
                'Container' => $process['hostname'],
                'Worker uptime' => $process['uptime_seconds'].' s',
                'Requests this worker' => $process['requests_served_by_this_worker'],
                'Octane' => $process['octane'] ? 'on' : 'off',
            ] as $label => $value)
                <div class="bg-slate-900 px-4 py-3">
                    <dt class="text-xs text-slate-500">{{ $label }}</dt>
                    <dd class="mt-0.5 truncate font-mono text-sm text-white">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="mt-2 text-xs text-slate-500">
            "Requests this worker" climbs on every reload. Under php-fpm it would always read 1,
            because the process is thrown away after each request.
        </p>
    </section>

    {{-- ---------- ecs ---------- --}}
    <section class="mt-8">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">ECS task</h2>
        @if ($ecs['available'])
            <dl class="mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-white/10 ring-1 ring-white/10 sm:grid-cols-4">
                @foreach ([
                    'Task ID' => $ecs['task_id'],
                    'Availability zone' => $ecs['availability_zone'],
                    'Cluster' => $ecs['cluster'],
                    'Launch type' => $ecs['launch_type'],
                    'Task family' => $ecs['family'],
                    'Revision' => $ecs['revision'],
                    'vCPU' => $ecs['cpu_limit'],
                    'Memory' => $ecs['memory_limit_mb'].' MB',
                ] as $label => $value)
                    <div class="bg-slate-900 px-4 py-3">
                        <dt class="text-xs text-slate-500">{{ $label }}</dt>
                        <dd class="mt-0.5 truncate font-mono text-sm text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @else
            <p class="mt-3 rounded-xl bg-slate-900 px-4 py-3 text-sm text-slate-400 ring-1 ring-white/10">
                Not on ECS ({{ $ecs['reason'] }}). Running locally.
            </p>
        @endif
    </section>

    {{-- ---------- dependencies ---------- --}}
    <section class="mt-8">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Dependencies</h2>
        <div class="mt-3 overflow-hidden rounded-xl ring-1 ring-white/10">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-white/5">
                @foreach ([
                    ['RDS PostgreSQL', $database['ok'], $database['ok']
                        ? $database['engine'].' · '.$database['latency_ms'].' ms · '.$database['reports'].' reports, '.$database['heartbeats'].' heartbeats'
                        : ($database['error'] ?? '')],
                    ['Cache store', $cache['ok'], $cache['ok']
                        ? 'driver: '.$cache['store'].' · write+read '.$cache['latency_ms'].' ms'
                        : ($cache['error'] ?? '')],
                    ['S3 bucket', $storage['ok'], $storage['ok']
                        ? $storage['bucket'].' · '.$storage['objects'].' objects · creds: '.$storage['credentials']
                        : ($storage['error'] ?? '')],
                    ['Queue', $queue['ok'], $queue['ok']
                        ? 'driver: '.$queue['connection'].' · '.$queue['pending'].' pending, '.$queue['failed'].' failed, '.$queue['completed_reports'].' reports done'
                        : ($queue['error'] ?? '')],
                    ['Scheduler', $scheduler['ok'], $scheduler['ok']
                        ? 'last heartbeat '.$scheduler['age_seconds'].' s ago from '.$scheduler['source']
                        : ($scheduler['reason'] ?? $scheduler['error'] ?? 'stale')],
                ] as [$name, $ok, $detail])
                    <tr class="bg-slate-900">
                        <td class="w-48 px-4 py-3 font-medium text-white">{{ $name }}</td>
                        <td class="w-24 px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 {{ $pill($ok) }}">
                                {{ $ok ? 'ok' : 'fail' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-400">{{ $detail }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- ---------- queue proof ---------- --}}
    <section class="mt-8">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Background jobs</h2>
            <form method="POST" action="/reports">
                @csrf
                <button class="rounded-lg bg-sky-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-400">
                    Queue a report
                </button>
            </form>
        </div>
        <p class="mt-2 text-xs text-slate-500">
            The button writes a row and dispatches a job. A different service, on a different task,
            picks it up, writes a CSV to S3, and marks the row complete. The <span class="font-mono">worker</span>
            column is the container that did it.
        </p>
        <div class="mt-3 overflow-hidden rounded-xl ring-1 ring-white/10">
            <table class="w-full text-left text-sm">
                <thead class="bg-white/5 text-xs uppercase tracking-wider text-slate-400">
                    <tr>
                        <th class="px-4 py-2 font-medium">#</th>
                        <th class="px-4 py-2 font-medium">Title</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Worker</th>
                        <th class="px-4 py-2 font-medium">Rows</th>
                        <th class="px-4 py-2 font-medium">Object</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                @forelse ($reports as $report)
                    <tr class="bg-slate-900">
                        <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ $report->id }}</td>
                        <td class="px-4 py-2 text-white">{{ $report->title }}</td>
                        <td class="px-4 py-2">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs ring-1 {{ $pill($report->status === 'completed') }}">
                                {{ $report->status }}
                            </span>
                        </td>
                        <td class="px-4 py-2 font-mono text-xs text-slate-400">{{ $report->worker ?? '—' }}</td>
                        <td class="px-4 py-2 font-mono text-xs text-slate-400">{{ $report->rows ?? '—' }}</td>
                        <td class="px-4 py-2 font-mono text-xs">
                            @if ($report->s3_key)
                                <a href="/reports/{{ $report->id }}/download" class="text-sky-400 hover:underline">{{ $report->s3_key }}</a>
                            @else
                                <span class="text-slate-600">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr class="bg-slate-900">
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500">
                            No reports yet. Queue one.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <footer class="mt-10 border-t border-white/10 pt-6 text-xs text-slate-600">
        Machine-readable copy of this page at <a href="/status.json" class="font-mono text-slate-400 hover:underline">/status.json</a>.
        Load balancer health check hits <span class="font-mono text-slate-400">/up</span>.
    </footer>
</div>
</body>
</html>
