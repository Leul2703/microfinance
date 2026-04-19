<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class PerformanceMonitorService
{
    private $metrics = [];
    private $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function startMonitoring()
    {
        $this->startTime = microtime(true);
        $this->metrics = [
            'memory_start' => memory_get_usage(true),
            'queries' => 0,
            'slow_queries' => [],
            'cache_hits' => 0,
            'cache_misses' => 0,
        ];
    }

    public function getCurrentMetrics()
    {
        $executionTime = (microtime(true) - $this->startTime) * 1000;
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);

        return [
            'execution_time_ms' => round($executionTime, 2),
            'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'memory_peak_mb' => round($memoryPeak / 1024 / 1024, 2),
            'queries_count' => $this->metrics['queries'],
            'slow_queries_count' => count($this->metrics['slow_queries']),
            'slow_queries' => $this->metrics['slow_queries'],
            'cache_hits' => $this->metrics['cache_hits'],
            'cache_misses' => $this->metrics['cache_misses'],
            'cache_hit_rate' => $this->calculateCacheHitRate(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function calculateCacheHitRate()
    {
        $total = $this->metrics['cache_hits'] + $this->metrics['cache_misses'];
        return $total > 0 ? round(($this->metrics['cache_hits'] / $total) * 100, 2) : 0;
    }

    public function getSystemPerformanceSummary()
    {
        return [
            'server' => $this->getServerMetrics(),
            'database' => $this->getDatabaseMetrics(),
            'cache' => $this->getCacheMetrics(),
            'storage' => $this->getStorageMetrics(),
            'recent_performance' => $this->getRecentPerformanceData(),
        ];
    }

    private function getServerMetrics()
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => config('app.timezone'),
            'environment' => app()->environment(),
        ];
    }

    private function getDatabaseMetrics()
    {
        try {
            $connection = DB::connection();
            $pdo = $connection->getPdo();

            return [
                'connection_name' => $connection->getName(),
                'driver' => $connection->getConfig('driver'),
                'database' => $connection->getConfig('database'),
                'host' => $connection->getConfig('host'),
                'status' => 'connected',
                'version' => $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION) ?? 'unknown',
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    private function getCacheMetrics()
    {
        try {
            $cache = Cache::getStore();
            return ['driver' => get_class($cache), 'status' => 'connected', 'default_ttl' => config('cache.ttl') ?? 'default'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    private function getStorageMetrics()
    {
        $storagePath = storage_path();

        return [
            'storage_path' => $storagePath,
            'disk_free_space' => disk_free_space($storagePath) ? round(disk_free_space($storagePath) / 1024 / 1024 / 1024, 2) . ' GB' : 'unknown',
            'disk_total_space' => disk_total_space($storagePath) ? round(disk_total_space($storagePath) / 1024 / 1024 / 1024, 2) . ' GB' : 'unknown',
        ];
    }

    private function getRecentPerformanceData()
    {
        $recentMetrics = Cache::get('performance_metrics_recent', []);

        return [
            'total_requests' => count($recentMetrics),
            'avg_execution_time' => count($recentMetrics) > 0
                ? round(array_sum(array_column($recentMetrics, 'execution_time_ms')) / count($recentMetrics), 2)
                : 0,
            'avg_memory_usage' => count($recentMetrics) > 0
                ? round(array_sum(array_column($recentMetrics, 'memory_usage_mb')) / count($recentMetrics), 2)
                : 0,
            'total_slow_queries' => array_sum(array_column($recentMetrics, 'slow_queries_count')),
        ];
    }

    public function storeMetrics($metrics)
    {
        $recentMetrics = Cache::get('performance_metrics_recent', []);
        array_unshift($recentMetrics, $metrics);

        if (count($recentMetrics) > 100) {
            $recentMetrics = array_slice($recentMetrics, 0, 100);
        }

        Cache::put('performance_metrics_recent', $recentMetrics, now()->addHours(1));

        $today = now()->format('Y-m-d');
        $dailyMetrics = Cache::get('performance_metrics_' . $today, []);
        $dailyMetrics[] = $metrics;

        Cache::put('performance_metrics_' . $today, $dailyMetrics, now()->addDays(7));
    }

    public function getPerformanceReport($startDate, $endDate)
    {
        $report = [];
        $currentDate = Carbon::parse($startDate);
        $endDate = Carbon::parse($endDate);

        while ($currentDate <= $endDate) {
            $dayKey = $currentDate->format('Y-m-d');
            $dayMetrics = Cache::get('performance_metrics_' . $dayKey, []);

            if (!empty($dayMetrics)) {
                $report[$dayKey] = [
                    'total_requests' => count($dayMetrics),
                    'avg_execution_time' => round(array_sum(array_column($dayMetrics, 'execution_time_ms')) / count($dayMetrics), 2),
                    'avg_memory_usage' => round(array_sum(array_column($dayMetrics, 'memory_usage_mb')) / count($dayMetrics), 2),
                    'total_slow_queries' => array_sum(array_column($dayMetrics, 'slow_queries_count')),
                    'avg_cache_hit_rate' => round(array_sum(array_column($dayMetrics, 'cache_hit_rate')) / count($dayMetrics), 2),
                ];
            }

            $currentDate->addDay();
        }

        return $report;
    }

    public function checkPerformanceRequirements()
    {
        $requirements = [
            'login_time' => ['threshold_ms' => 500, 'description' => 'Login response time should be under 500ms'],
            'transaction_latency' => ['threshold_ms' => 2000, 'description' => 'Transaction processing should complete within 2 seconds'],
            'reporting_speed' => ['threshold_ms' => 60000, 'description' => 'Report generation should complete within 1 minute'],
        ];

        $currentMetrics = $this->getCurrentMetrics();
        $status = [];

        foreach ($requirements as $key => $requirement) {
            $status[$key] = [
                'threshold_ms' => $requirement['threshold_ms'],
                'current_ms' => $currentMetrics['execution_time_ms'],
                'met' => $currentMetrics['execution_time_ms'] <= $requirement['threshold_ms'],
                'description' => $requirement['description'],
            ];
        }

        return ['requirements' => $status, 'overall_status' => collect($status)->every('met'), 'current_metrics' => $currentMetrics];
    }

    public function getOptimizationSuggestions()
    {
        $suggestions = [];
        $metrics = $this->getCurrentMetrics();
        $systemMetrics = $this->getSystemPerformanceSummary();

        if ($metrics['cache_hit_rate'] < 70) {
            $suggestions[] = [
                'priority' => 'medium',
                'category' => 'caching',
                'issue' => 'Low cache hit rate',
                'suggestion' => 'Consider caching frequently accessed data to improve performance.',
                'current_rate' => $metrics['cache_hit_rate'] . '%',
            ];
        }

        if ($metrics['memory_usage_mb'] > 100) {
            $suggestions[] = [
                'priority' => 'medium',
                'category' => 'memory',
                'issue' => 'High memory usage',
                'suggestion' => 'Review memory-intensive operations and consider optimizing data loading.',
                'usage_mb' => $metrics['memory_usage_mb'],
            ];
        }

        if ($metrics['execution_time_ms'] > 1000) {
            $suggestions[] = [
                'priority' => 'high',
                'category' => 'performance',
                'issue' => 'Slow response time',
                'suggestion' => 'Review code for performance bottlenecks and consider implementing queue jobs for heavy operations.',
                'execution_time_ms' => $metrics['execution_time_ms'],
            ];
        }

        if ($systemMetrics['database']['status'] !== 'connected') {
            $suggestions[] = [
                'priority' => 'critical',
                'category' => 'database',
                'issue' => 'Database connection issue',
                'suggestion' => 'Check database connection settings and ensure database server is accessible.',
                'error' => $systemMetrics['database']['error'] ?? 'Unknown error',
            ];
        }

        return $suggestions;
    }
}