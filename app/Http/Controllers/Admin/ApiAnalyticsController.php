<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ApiAnalyticsController extends Controller
{
    public function __construct()
    {
        view()->share('adminNav', CronDashboardController::navItems());
    }

    public function index(Request $request)
    {
        $days = (int) $request->input('days', 1);
        $days = $days < 1 ? 1 : ($days > 30 ? 30 : $days);

        $jobFilter = trim((string) $request->input('job'));
        $statusFilter = strtolower((string) $request->input('status', ''));
        $methodFilter = strtoupper(trim((string) $request->input('method', '')));
        $tagFilter = trim((string) $request->input('tag', ''));

        $from = Carbon::now()->subDays($days);

        $baseQuery = ApiRequestLog::query()
            ->where('requested_at', '>=', $from);

        if ($jobFilter !== '') {
            $baseQuery->where('job_key', $jobFilter);
        }

        if ($statusFilter === 'success') {
            $baseQuery->where('is_error', false);
        } elseif ($statusFilter === 'error') {
            $baseQuery->where('is_error', true);
        }

        if ($methodFilter !== '') {
            $baseQuery->where('method', $methodFilter);
        }

        if ($tagFilter !== '') {
            $baseQuery->where('tag', $tagFilter);
        }

        $summaryQuery = clone $baseQuery;
        $successQuery = clone $baseQuery;
        $errorQuery = clone $baseQuery;

        $totalCalls = (clone $summaryQuery)->count();
        $successCalls = (clone $successQuery)->where('is_error', false)->count();
        $errorCalls = (clone $errorQuery)->where('is_error', true)->count();

        $averageDuration = (clone $baseQuery)->whereNotNull('duration_ms')->avg('duration_ms');
        $averageDuration = $averageDuration !== null ? round($averageDuration, 2) : null;

        $topHosts = (clone $baseQuery)
            ->select('host', DB::raw('COUNT(*) as total'), DB::raw('SUM(is_error) as error_total'))
            ->whereNotNull('host')
            ->groupBy('host')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        $topTags = (clone $baseQuery)
            ->select('tag', DB::raw('COUNT(*) as total'))
            ->whereNotNull('tag')
            ->groupBy('tag')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        $logs = $baseQuery
            ->orderByDesc('requested_at')
            ->paginate(50)
            ->withQueryString();

        $jobKeys = ApiRequestLog::query()
            ->select('job_key')
            ->whereNotNull('job_key')
            ->distinct()
            ->orderBy('job_key')
            ->pluck('job_key');

        $methods = ApiRequestLog::query()
            ->select('method')
            ->distinct()
            ->orderBy('method')
            ->pluck('method');

        return view('admin.api-analytics', [
            'pageTitle' => 'API Analytics',
            'totalCalls' => $totalCalls,
            'successCalls' => $successCalls,
            'errorCalls' => $errorCalls,
            'averageDuration' => $averageDuration,
            'topHosts' => $topHosts,
            'topTags' => $topTags,
            'logs' => $logs,
            'jobKeys' => $jobKeys,
            'methods' => $methods,
            'filters' => [
                'days' => $days,
                'job' => $jobFilter,
                'status' => $statusFilter,
                'method' => $methodFilter,
                'tag' => $tagFilter,
            ],
        ]);
    }
}
