<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LiveMatchSyncLog;
use App\Models\MatchOversSyncLog;
use App\Models\SeriesSyncLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CronDashboardController extends Controller
{
    /**
     * Metadata for each tracked job.
     */
    private const JOBS = [
        'series' => [
            'label'       => 'Series Sync',
            'description' => 'Ingests Cricbuzz series, metadata, and related matches into Firestore.',
            'accent'      => 'indigo',
            'model'       => SeriesSyncLog::class,
        ],
        'live-matches' => [
            'label'       => 'Live Matches Sync',
            'description' => 'Streams live match snapshots into matches & matchInfo collections.',
            'accent'      => 'emerald',
            'model'       => LiveMatchSyncLog::class,
        ],
        'match-overs' => [
            'label'       => 'Match Overs Sync',
            'description' => 'Captures live over-by-over miniscores for active matches.',
            'accent'      => 'rose',
            'model'       => MatchOversSyncLog::class,
        ],
    ];

    public function __construct()
    {
        view()->share('adminNav', self::navItems());
    }

    public function index(Request $request)
    {
        $days = (int) $request->input('days', 1);
        $days = $days < 1 ? 1 : ($days > 30 ? 30 : $days);

        $summaries = [];
        foreach (self::JOBS as $key => $config) {
            $summaries[$key] = $this->buildSummary($key, $config, $days);
        }

        return view('admin.dashboard', [
            'pageTitle' => 'Cron Analytics Overview',
            'jobsNav'   => self::JOBS,
            'summaries' => $summaries,
            'days'      => $days,
        ]);
    }

    public function job(Request $request, string $job)
    {
        $jobConfig = $this->resolveJob($job);
        $window    = max(1, (int) $request->input('days', 7));
        $summary   = $this->buildSummary($job, $jobConfig, $window);

        $modelClass = $jobConfig['model'];
        $table      = (new $modelClass())->getTable();

        $runsQuery = DB::table($table)
            ->selectRaw('run_id, MIN(created_at) as started_at, MAX(created_at) as finished_at')
            ->selectRaw('COUNT(*) as event_count')
            ->selectRaw('SUM(CASE WHEN status = "error" THEN 1 ELSE 0 END) as error_count')
            ->selectRaw('SUM(CASE WHEN status = "warning" THEN 1 ELSE 0 END) as warning_count')
            ->selectRaw('SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as success_count')
            ->selectRaw('MAX(CASE WHEN action = "job_completed" THEN status END) as final_status')
            ->groupBy('run_id');

        if ($search = trim((string) $request->input('run'))) {
            $runsQuery->where('run_id', 'like', "%{$search}%");
        }

        $runs = $runsQuery
            ->orderByDesc('finished_at')
            ->paginate(25)
            ->withQueryString();

        $runs->getCollection()->transform(function ($run) {
            $run->started_at  = $run->started_at ? Carbon::parse($run->started_at) : null;
            $run->finished_at = $run->finished_at ? Carbon::parse($run->finished_at) : null;
            $run->duration_seconds = ($run->started_at && $run->finished_at)
                ? max(0, $run->started_at->diffInSeconds($run->finished_at))
                : null;
            $run->duration_human = $this->formatDuration($run->duration_seconds);
            $run->final_status      = $run->final_status ?: ($run->error_count > 0 ? 'error' : ($run->warning_count > 0 ? 'warning' : 'success'));
            $run->error_count       = (int) $run->error_count;
            $run->warning_count     = (int) $run->warning_count;
            $run->success_count     = (int) $run->success_count;
            $run->event_count       = (int) $run->event_count;

            return $run;
        });

        return view('admin.job', [
            'pageTitle' => $jobConfig['label'] . ' Runs',
            'jobsNav'   => self::JOBS,
            'job'       => $jobConfig + ['key' => $job],
            'summary'   => $summary,
            'runs'      => $runs,
            'search'    => $search,
            'days'      => $window,
        ]);
    }

    public function run(string $job, string $runId)
    {
        $jobConfig = $this->resolveJob($job);
        $modelClass = $jobConfig['model'];

        $logs = $modelClass::query()
            ->where('run_id', $runId)
            ->orderBy('created_at')
            ->get();

        abort_if($logs->isEmpty(), 404);

        $summary = $this->mapRun($logs, $job);

        return view('admin.run', [
            'pageTitle' => $jobConfig['label'] . ' · Run ' . $runId,
            'jobsNav'   => self::JOBS,
            'job'       => $jobConfig + ['key' => $job],
            'run'       => $summary,
            'logs'      => $logs,
        ]);
    }

    /**
     * Build the navigation structure shared across admin pages.
     */
    public static function navItems(): array
    {
        $items = [
            [
                'key'     => 'dashboard',
                'label'   => 'Overview',
                'href'    => route('admin.dashboard'),
                'pattern' => ['admin-yash'],
            ],
        ];

        foreach (self::JOBS as $key => $config) {
            $items[] = [
                'key'     => $key,
                'label'   => $config['label'],
                'href'    => route('admin.jobs.show', $key),
                'pattern' => ['admin-yash/jobs/' . $key . '*'],
            ];
        }

        $items[] = [
            'key'     => 'pause-window',
            'label'   => 'Pause Window',
            'href'    => route('admin.pause-window.edit'),
            'pattern' => ['admin-yash/pause-window*'],
        ];

        return $items;
    }

    protected function resolveJob(string $job): array
    {
        if (!array_key_exists($job, self::JOBS)) {
            abort(404);
        }

        return self::JOBS[$job] + ['key' => $job];
    }

    protected function buildSummary(string $key, array $config, int $days): array
    {
        $modelClass = $config['model'];
        $table      = (new $modelClass())->getTable();
        $windowFrom = now()->subDays($days);

        $totalRuns     = DB::table($table)->distinct('run_id')->count('run_id');
        $windowRuns    = DB::table($table)->where('created_at', '>=', $windowFrom)->distinct('run_id')->count('run_id');
        $lastLogRecord = DB::table($table)->orderByDesc('created_at')->first();
        $lastRunAt     = $lastLogRecord?->created_at ? Carbon::parse($lastLogRecord->created_at) : null;

        $statusBreakdown = DB::table($table)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->whereNotNull('status')
            ->where('created_at', '>=', $windowFrom)
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $latestRunIds = DB::table($table)
            ->select('run_id', DB::raw('MAX(created_at) as finished_at'))
            ->groupBy('run_id')
            ->orderByDesc('finished_at')
            ->limit(5)
            ->pluck('run_id')
            ->toArray();

        $recentRuns = collect();

        if (!empty($latestRunIds)) {
            $logGroups = $modelClass::query()
                ->whereIn('run_id', $latestRunIds)
                ->orderBy('created_at')
                ->get()
                ->groupBy('run_id');

            $recentRuns = collect($latestRunIds)
                ->filter(fn ($id) => $logGroups->has($id))
                ->map(function ($id) use ($logGroups, $key) {
                    return $this->mapRun($logGroups->get($id), $key);
                });
        }

        $recentIssues = $modelClass::query()
            ->whereIn('status', ['error', 'warning'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return $config + [
            'key'              => $key,
            'total_runs'       => $totalRuns,
            'runs_last_window' => $windowRuns,
            'last_run_at'      => $lastRunAt,
            'status_breakdown' => $statusBreakdown,
            'recent_runs'      => $recentRuns,
            'recent_issues'    => $recentIssues,
            'window_days'      => $days,
        ];
    }

    protected function mapRun(Collection $logs, string $jobKey): array
    {
        $ordered      = $logs->sortBy('created_at')->values();
        $first        = $ordered->first();
        $last         = $ordered->last();
        $jobCompleted = $ordered->firstWhere('action', 'job_completed');

        $finishedAt = $jobCompleted?->created_at ?? $last?->created_at;
        $duration   = ($first && $finishedAt)
            ? max(0, $first->created_at->diffInSeconds($finishedAt))
            : null;

        $finalStatus = $jobCompleted?->status
            ?? ($last?->status ?? 'info');

        return [
            'job_key'          => $jobKey,
            'run_id'           => $first?->run_id,
            'started_at'       => $first?->created_at,
            'finished_at'      => $finishedAt,
            'duration_seconds' => $duration,
            'duration_human'   => $this->formatDuration($duration),
            'error_count'      => $ordered->where('status', 'error')->count(),
            'warning_count'    => $ordered->where('status', 'warning')->count(),
            'success_count'    => $ordered->where('status', 'success')->count(),
            'info_count'       => $ordered->where('status', 'info')->count(),
            'total_events'     => $ordered->count(),
            'final_status'     => $finalStatus,
            'summary_message'  => $jobCompleted?->message ?? $last?->message,
        ];
    }

    protected function formatDuration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);
            $remain  = $seconds % 60;

            return $minutes . 'm' . ($remain ? ' ' . $remain . 's' : '');
        }

        $hours  = intdiv($seconds, 3600);
        $remain = $seconds % 3600;
        $minutes = intdiv($remain, 60);
        $seconds = $remain % 60;

        return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
    }
}
