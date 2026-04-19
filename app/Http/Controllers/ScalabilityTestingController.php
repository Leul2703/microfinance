<?php

namespace App\Http\Controllers;

use App\Services\ScalabilityTestingService;
use Illuminate\Http\Request;

class ScalabilityTestingController extends Controller
{
    private $scalabilityService;

    public function __construct(ScalabilityTestingService $scalabilityService)
    {
        $this->scalabilityService = $scalabilityService;
    }

    public function index()
    {
        return view('admin.scalability-testing');
    }

    public function runLoadTest(Request $request)
    {
        $request->validate([
            'concurrent_users' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'test_duration_seconds' => ['nullable', 'integer', 'min:10', 'max:300'],
            'ramp_up_time' => ['nullable', 'integer', 'min:0', 'max:60'],
            'operations_per_user' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $config = [
            'concurrent_users' => $request->concurrent_users ?? 10,
            'test_duration_seconds' => $request->test_duration_seconds ?? 30,
            'ramp_up_time' => $request->ramp_up_time ?? 5,
            'operations_per_user' => $request->operations_per_user ?? 5,
        ];

        $results = $this->scalabilityService->runLoadTest($config);

        return response()->json($results);
    }

    public function getCapacityPlanning()
    {
        return response()->json($this->scalabilityService->calculateCapacityPlanning());
    }

    public function generateReport()
    {
        return response()->json($this->scalabilityService->generateScalabilityReport());
    }

    public function getSystemMetrics()
    {
        return response()->json($this->scalabilityService->getCurrentSystemMetrics());
    }

    public function getRecommendations()
    {
        $capacityPlan = $this->scalabilityService->calculateCapacityPlanning();
        return response()->json([
            'recommendations' => $capacityPlan['recommendations'],
            'bottlenecks' => $capacityPlan['bottlenecks'],
        ]);
    }

    public function exportReport(Request $request)
    {
        $request->validate([
            'format' => ['required', 'in:json,csv'],
        ]);

        $report = $this->scalabilityService->generateScalabilityReport();

        if ($request->format === 'json') {
            return response()->json($report)
                ->header('Content-Disposition', 'attachment; filename="scalability_report_' . now()->format('Y-m-d') . '.json"');
        }

        return $this->exportCsv($report);
    }

    private function exportCsv($report)
    {
        $csv = fopen('php://temp', 'r+');

        fputcsv($csv, ['Scalability Test Report']);
        fputcsv($csv, ['Generated At', $report['generated_at']]);
        fputcsv($csv, []);
        fputcsv($csv, ['Load Test Results']);
        fputcsv($csv, ['Concurrent Users', $report['load_test']['config']['concurrent_users']]);
        fputcsv($csv, ['Test Duration (seconds)', $report['load_test']['test_duration_seconds']]);
        fputcsv($csv, ['Total Operations', $report['load_test']['total_operations']]);
        fputcsv($csv, ['Successful Operations', $report['load_test']['successful_operations']]);
        fputcsv($csv, ['Failed Operations', $report['load_test']['failed_operations']]);
        fputcsv($csv, ['Average Response Time (ms)', $report['load_test']['average_response_time_ms']]);
        fputcsv($csv, ['Throughput (ops/sec)', $report['load_test']['throughput_per_second']]);

        rewind($csv);
        $csvContent = stream_get_contents($csv);
        fclose($csv);

        $filename = 'scalability_report_' . now()->format('Y-m-d') . '.csv';

        return response($csvContent)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function quickCheck()
    {
        $quickTest = $this->scalabilityService->runLoadTest([
            'concurrent_users' => 5,
            'test_duration_seconds' => 10,
            'ramp_up_time' => 2,
            'operations_per_user' => 3,
        ]);

        return response()->json([
            'quick_test' => $quickTest,
            'current_metrics' => $this->scalabilityService->getCurrentSystemMetrics(),
            'status' => $quickTest['failed_operations'] === 0 ? 'passed' : 'failed',
        ]);
    }
}