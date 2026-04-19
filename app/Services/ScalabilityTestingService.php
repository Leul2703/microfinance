<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ScalabilityTestingService
{
    public function runLoadTest($config = [])
    {
        $config = array_merge([
            'concurrent_users' => 10,
            'test_duration_seconds' => 30,
            'ramp_up_time' => 5,
            'operations_per_user' => 5,
        ], $config);

        $results = [
            'config' => $config,
            'start_time' => now()->toIso8601String(),
            'end_time' => null,
            'total_operations' => 0,
            'successful_operations' => 0,
            'failed_operations' => 0,
            'average_response_time_ms' => 0,
            'max_response_time_ms' => 0,
            'min_response_time_ms' => PHP_FLOAT_MAX,
            'throughput_per_second' => 0,
            'errors' => [],
        ];

        $responseTimes = [];
        $startTime = microtime(true);

        for ($i = 0; $i < $config['concurrent_users']; $i++) {
            for ($j = 0; $j < $config['operations_per_user']; $j++) {
                $opStart = microtime(true);
                try {
                    DB::select('SELECT 1');
                    Cache::put('scale_ping_' . $i . '_' . $j, 1, 1);
                    Cache::get('scale_ping_' . $i . '_' . $j);
                    $results['successful_operations']++;
                } catch (\Exception $e) {
                    $results['failed_operations']++;
                    $results['errors'][] = $e->getMessage();
                }

                $elapsed = (microtime(true) - $opStart) * 1000;
                $results['total_operations']++;
                $responseTimes[] = $elapsed;
                $results['max_response_time_ms'] = max($results['max_response_time_ms'], $elapsed);
                $results['min_response_time_ms'] = min($results['min_response_time_ms'], $elapsed);

                if ((microtime(true) - $startTime) > $config['test_duration_seconds']) {
                    break 2;
                }
            }
        }

        $duration = max(0.001, microtime(true) - $startTime);
        $results['end_time'] = now()->toIso8601String();
        $results['test_duration_seconds'] = round($duration, 2);
        $results['average_response_time_ms'] = empty($responseTimes) ? 0 : round(array_sum($responseTimes) / count($responseTimes), 2);
        $results['throughput_per_second'] = round($results['total_operations'] / $duration, 2);

        return $results;
    }

    public function calculateCapacityPlanning($currentMetrics = null)
    {
        if (!$currentMetrics) {
            $currentMetrics = $this->getCurrentSystemMetrics();
        }

        $growthScenarios = [
            'conservative' => 0.15,
            'moderate' => 0.20,
            'aggressive' => 0.25,
        ];

        $capacityPlan = [
            'current_metrics' => $currentMetrics,
            'projections' => [],
            'bottlenecks' => [],
            'recommendations' => [],
        ];

        foreach ($growthScenarios as $scenario => $rate) {
            for ($year = 1; $year <= 5; $year++) {
                $capacityPlan['projections'][$scenario][$year] = [
                    'year' => $year,
                    'projected_users' => round($currentMetrics['total_users'] * pow(1 + $rate, $year)),
                    'projected_transactions' => round($currentMetrics['daily_transactions'] * pow(1 + $rate, $year)),
                    'projected_storage_gb' => round($currentMetrics['storage_gb'] * pow(1 + $rate, $year), 2),
                    'growth_rate' => ($rate * 100) . '%',
                ];
            }
        }

        $capacityPlan['bottlenecks'] = $this->identifyBottlenecks($currentMetrics);
        $capacityPlan['recommendations'] = $this->generateScalabilityRecommendations($currentMetrics, $capacityPlan['projections']);

        return $capacityPlan;
    }

    public function getCurrentSystemMetrics()
    {
        return [
            'total_users' => \App\Models\User::count(),
            'total_customers' => \App\Models\Customer::count(),
            'total_loans' => \App\Models\Loan::count(),
            'total_savings' => \App\Models\SavingsAccount::count(),
            'daily_transactions' => \App\Models\Repayment::whereDate('payment_date', today())->count() + \App\Models\SavingsTransaction::whereDate('posted_at', today())->count(),
            'storage_gb' => round(disk_total_space(storage_path()) / 1024 / 1024 / 1024, 2),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'cpu_load' => function_exists('sys_getloadavg') ? sys_getloadavg()[0] : 0,
        ];
    }

    private function identifyBottlenecks($metrics)
    {
        $b = [];
        if ($metrics['memory_usage_mb'] > 500) {
            $b[] = ['type' => 'memory', 'severity' => 'warning', 'message' => 'Memory usage is high'];
        }
        if ($metrics['daily_transactions'] > 10000) {
            $b[] = ['type' => 'throughput', 'severity' => 'warning', 'message' => 'Daily transaction volume is high'];
        }
        return $b;
    }

    private function generateScalabilityRecommendations($currentMetrics, $projections)
    {
        return [
            ['category' => 'database', 'priority' => 'high', 'timeline' => '1-2 years', 'recommendation' => 'Implement query optimization and read replicas.'],
            ['category' => 'caching', 'priority' => 'medium', 'timeline' => '3-6 months', 'recommendation' => 'Expand caching for reports and dashboards.'],
            ['category' => 'load_balancing', 'priority' => 'high', 'timeline' => '6-12 months', 'recommendation' => 'Prepare horizontal scaling with load balancing.'],
        ];
    }

    public function generateScalabilityReport()
    {
        $loadTest = $this->runLoadTest([
            'concurrent_users' => 50,
            'test_duration_seconds' => 60,
            'ramp_up_time' => 10,
            'operations_per_user' => 10,
        ]);

        $capacity = $this->calculateCapacityPlanning();

        return [
            'load_test' => $loadTest,
            'capacity_planning' => $capacity,
            'system_readiness' => [
                'score' => $loadTest['failed_operations'] === 0 ? 85 : 65,
                'status' => $loadTest['failed_operations'] === 0 ? 'ready' : 'needs_improvement',
                'issues' => $loadTest['failed_operations'] === 0 ? [] : ['Load test produced failures'],
                'recommendations' => $capacity['recommendations'],
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}