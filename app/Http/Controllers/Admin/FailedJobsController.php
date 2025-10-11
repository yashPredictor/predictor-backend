<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FailedJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FailedJobsController extends Controller
{
    private const SAMPLE_FOR_ANALYTICS = 200;

    public function __construct()
    {
        view()->share('adminNav', CronDashboardController::navItems());
    }

    public function index(Request $request): View
    {
        $search     = trim((string) $request->input('search', ''));
        $queue      = trim((string) $request->input('queue', ''));
        $perPage    = (int) $request->input('per_page', 25);
        $perPage    = $perPage < 10 ? 10 : ($perPage > 100 ? 100 : $perPage);

        $query = FailedJob::query();

        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('uuid', 'like', "%{$search}%")
                    ->orWhere('exception', 'like', "%{$search}%")
                    ->orWhere('payload', 'like', "%{$search}%")
                    ->orWhere('connection', 'like', "%{$search}%")
                    ->orWhere('queue', 'like', "%{$search}%");
            });
        }

        if ($queue !== '') {
            $query->where('queue', $queue);
        }

        $failedJobs = $query
            ->orderByDesc('failed_at')
            ->paginate($perPage)
            ->withQueryString();

        $failedJobs->getCollection()->transform(function (FailedJob $failedJob) {
            $payload = $failedJob->payloadAsArray();
            $attempts = Arr::get($payload, 'attempts');
            if (!is_numeric($attempts)) {
                $attempts = Arr::get($payload, 'data.commandAttempts');
            }

            $attempts = is_numeric($attempts) ? (int) $attempts : null;
            $payloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return [
                'id'                 => $failedJob->id,
                'uuid'               => $failedJob->uuid,
                'queue'              => $failedJob->queue,
                'connection'         => $failedJob->connection,
                'failed_at'          => $failedJob->failed_at,
                'display_name'       => $failedJob->displayName(),
                'job_class'          => $failedJob->jobClass(),
                'exception_headline' => $failedJob->exceptionHeadline(),
                'exception'          => $failedJob->exception,
                'attempts'           => $attempts,
                'payload_json'       => $payloadJson !== false ? $payloadJson : null,
            ];
        });

        $queues = FailedJob::query()
            ->select('queue')
            ->distinct()
            ->orderBy('queue')
            ->pluck('queue')
            ->filter()
            ->values();

        $stats = $this->buildAnalytics();

        return view('admin.failed-jobs', [
            'pageTitle'   => 'Failed Jobs',
            'pageIntro'   => 'Inspect failed queue jobs, diagnose the root cause, and selectively retry payloads back onto the queue.',
            'jobs'        => $failedJobs,
            'queues'      => $queues,
            'filters'     => [
                'search'   => $search,
                'queue'    => $queue,
                'per_page' => $perPage,
            ],
            'stats'       => $stats,
        ]);
    }

    public function retry(Request $request, FailedJob $failedJob): RedirectResponse
    {
        try {
            Queue::connection($failedJob->connection)
                ->pushRaw($failedJob->payload, $failedJob->queue);

            $failedJob->delete();
        } catch (\Throwable $exception) {
            return redirect()
                ->back()
                ->withInput($request->except('_token'))
                ->with('toast', [
                    'type'    => 'error',
                    'message' => 'Retry failed: ' . Str::limit($exception->getMessage(), 180),
                    'emoji'   => '⚠️',
                ]);
        }

        return redirect()
            ->route('admin.failed-jobs.index', $request->only(['queue', 'search', 'per_page']))
            ->with('toast', [
                'type'    => 'success',
                'message' => 'Job dispatched back onto the queue.',
                'emoji'   => '🔁',
            ]);
    }

    private function buildAnalytics(): array
    {
        $now = Carbon::now();

        $total = FailedJob::count();
        $lastDay = FailedJob::where('failed_at', '>=', $now->clone()->subDay())->count();
        $lastHour = FailedJob::where('failed_at', '>=', $now->clone()->subHour())->count();
        $oldest = FailedJob::min('failed_at');
        $latest = FailedJob::max('failed_at');

        $queues = DB::table('failed_jobs')
            ->select('queue', DB::raw('COUNT(*) as count'))
            ->groupBy('queue')
            ->orderByDesc('count')
            ->limit(6)
            ->get()
            ->map(fn ($row) => [
                'queue' => $row->queue ?: 'default',
                'count' => (int) $row->count,
            ]);

        $sample = FailedJob::query()
            ->latest('failed_at')
            ->limit(self::SAMPLE_FOR_ANALYTICS)
            ->get();

        $jobTypeTally = [];
        $reasonTally  = [];

        /** @var FailedJob $failed */
        foreach ($sample as $failed) {
            $jobName = $failed->displayName();
            $jobTypeTally[$jobName] = ($jobTypeTally[$jobName] ?? 0) + 1;

            $headline = $failed->exceptionHeadline();
            $reasonTally[$headline] = ($reasonTally[$headline] ?? 0) + 1;
        }

        $topJobTypes = $this->normaliseTopList($jobTypeTally);
        $topReasons  = $this->normaliseTopList($reasonTally);

        return [
            'total'        => $total,
            'last_day'     => $lastDay,
            'last_hour'    => $lastHour,
            'oldest'       => $oldest ? Carbon::parse($oldest) : null,
            'latest'       => $latest ? Carbon::parse($latest) : null,
            'queues'       => $queues,
            'top_job_types'=> $topJobTypes,
            'top_reasons'  => $topReasons,
            'sample_size'  => $sample->count(),
        ];
    }

    /**
     * @param array<string, int> $tally
     * @return array<int, array{label: string, count: int}>
     */
    private function normaliseTopList(array $tally): array
    {
        if (empty($tally)) {
            return [];
        }

        return collect($tally)
            ->map(fn ($count, $label) => [
                'label' => $label,
                'count' => (int) $count,
            ])
            ->sortByDesc('count')
            ->take(6)
            ->values()
            ->all();
    }
}
