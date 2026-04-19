<?php

namespace App\Http\Controllers;

use App\Services\PerformanceMonitorService;
use Illuminate\Http\Request;

class PerformanceMonitorController extends Controller
{
    private $performanceService;

    public function __construct(PerformanceMonitorService $performanceService)
    {
        $this->performanceService = $performanceService;
    }

    public function index()
    {
        $this->performanceService->startMonitoring();

        $systemMetrics = $this->performanceService->getSystemPerformanceSummary();
        $requirements = $this->performanceService->checkPerformanceRequirements();
        $suggestions = $this->performanceService->getOptimizationSuggestions();
        $currentMetrics = $this->performanceService->getCurrentMetrics();

        return view('admin.performance-monitor', [
            'systemMetrics' => $systemMetrics,
            'requirements' => $requirements,
            'suggestions' => $suggestions,
            'currentMetrics' => $currentMetrics,
        ]);
    }

    public function getMetrics()
    {
        $this->performanceService->startMonitoring();
        $metrics = $this->performanceService->getCurrentMetrics();
        $this->performanceService->storeMetrics($metrics);

        return response()->json($metrics);
    }

    public function getSystemSummary()
    {
        $summary = $this->performanceService->getSystemPerformanceSummary();
        return response()->json($summary);
    }

    public function getReport(Request $request)
    {
        $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $report = $this->performanceService->getPerformanceReport(
            $request->start_date,
            $request->end_date
        );

        return response()->json($report);
    }

    public function checkRequirements()
    {
        $requirements = $this->performanceService->checkPerformanceRequirements();
        return response()->json($requirements);
    }

    public function getOptimizationSuggestions()
    {
        $suggestions = $this->performanceService->getOptimizationSuggestions();
        return response()->json($suggestions);
    }

    public function clearCache()
    {
        \Illuminate\Support\Facades\Cache::forget('performance_metrics_recent');

        for ($i = 0; $i < 7; $i++) {
            $dayKey = now()->subDays($i)->format('Y-m-d');
            \Illuminate\Support\Facades\Cache::forget('performance_metrics_' . $dayKey);
        }

        return response()->json([
            'success' => true,
            'message' => 'Performance cache cleared successfully'
        ]);
    }

    public function runPerformanceTest(Request $request)
    {
        $request->validate([
            'test_type' => ['required', 'in:login,database,cache,report'],
        ]);

        $testType = $request->test_type;
        $results = [];

        switch ($testType) {
            case 'login':
                $results = $this->testLoginPerformance();
                break;
            case 'database':
                $results = $this->testDatabasePerformance();
                break;
            case 'cache':
                $results = $this->testCachePerformance();
                break;
            case 'report':
                $results = $this->testReportPerformance();
                break;
        }

        return response()->json($results);
    }

    private function testLoginPerformance()
    {
        $this->performanceService->startMonitoring();
        $startTime = microtime(true);
        \Illuminate\Support\Facades\Auth::check();
        $executionTime = (microtime(true) - $startTime) * 1000;

        return [
            'test_type' => 'login',
            'execution_time_ms' => round($executionTime, 2),
            'threshold_ms' => 500,
            'passed' => $executionTime < 500,
            'metrics' => $this->performanceService->getCurrentMetrics(),
        ];
    }

    private function testDatabasePerformance()
    {
        $this->performanceService->startMonitoring();

        $startTime = microtime(true);
        $queryTime = 0;
        $testQueries = [
            'SELECT COUNT(*) FROM users',
            'SELECT COUNT(*) FROM customers',
            'SELECT COUNT(*) FROM loans',
            'SELECT COUNT(*) FROM savings_accounts',
        ];

        foreach ($testQueries as $sql) {
            $queryStart = microtime(true);
            \Illuminate\Support\Facades\DB::select($sql);
            $queryTime += (microtime(true) - $queryStart) * 1000;
        }

        $totalTime = (microtime(true) - $startTime) * 1000;

        return [
            'test_type' => 'database',
            'total_time_ms' => round($totalTime, 2),
            'query_time_ms' => round($queryTime, 2),
            'queries_executed' => count($testQueries),
            'avg_query_time_ms' => round($queryTime / count($testQueries), 2),
            'threshold_ms' => 1000,
            'passed' => $totalTime < 1000,
            'metrics' => $this->performanceService->getCurrentMetrics(),
        ];
    }

    private function testCachePerformance()
    {
        $this->performanceService->startMonitoring();

        $startTime = microtime(true);
        $cacheKey = 'performance_test_' . time();
        $cacheValue = str_repeat('test data ', 1000);

        $writeStart = microtime(true);
        \Illuminate\Support\Facades\Cache::put($cacheKey, $cacheValue, 60);
        $writeTime = (microtime(true) - $writeStart) * 1000;

        $readStart = microtime(true);
        $retrievedValue = \Illuminate\Support\Facades\Cache::get($cacheKey);
        $readTime = (microtime(true) - $readStart) * 1000;

        \Illuminate\Support\Facades\Cache::forget($cacheKey);

        $totalTime = (microtime(true) - $startTime) * 1000;

        return [
            'test_type' => 'cache',
            'total_time_ms' => round($totalTime, 2),
            'write_time_ms' => round($writeTime, 2),
            'read_time_ms' => round($readTime, 2),
            'data_size_kb' => round(strlen($cacheValue) / 1024, 2),
            'cache_hit' => $retrievedValue === $cacheValue,
            'threshold_ms' => 100,
            'passed' => $totalTime < 100,
            'metrics' => $this->performanceService->getCurrentMetrics(),
        ];
    }

    private function testReportPerformance()
    {
        $this->performanceService->startMonitoring();

        $startTime = microtime(true);

        try {
            $customers = \App\Models\Customer::limit(100)->get();
            $loans = \App\Models\Loan::limit(100)->get();
            $savings = \App\Models\SavingsAccount::limit(100)->get();

            $reportData = [
                'customers' => $customers->count(),
                'loans' => $loans->count(),
                'savings' => $savings->count(),
                'generated_at' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            $reportData = ['error' => $e->getMessage()];
        }

        $executionTime = (microtime(true) - $startTime) * 1000;

        return [
            'test_type' => 'report',
            'execution_time_ms' => round($executionTime, 2),
            'threshold_ms' => 60000,
            'passed' => $executionTime < 60000,
            'report_data' => $reportData,
            'metrics' => $this->performanceService->getCurrentMetrics(),
        ];
    }
}