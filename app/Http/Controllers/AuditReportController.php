<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditReportController extends Controller
{
    public function index()
    {
        return view('admin.audit-reports');
    }

    public function generateReport(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'user_id' => 'nullable|exists:users,id',
            'action' => 'nullable|string',
            'model_type' => 'nullable|string',
            'export_format' => 'nullable|in:json,csv,excel'
        ]);

        $query = AuditLog::query()
            ->with('user:id,name')
            ->whereBetween('created_at', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59'
            ]);

        // Apply filters
        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->action) {
            $query->where('action', 'like', '%' . $request->action . '%');
        }

        if ($request->model_type) {
            $query->where('model_type', 'like', '%' . $request->model_type . '%');
        }

        $logs = $query->orderByDesc('created_at')->get();

        // Generate statistics
        $statistics = AuditLogService::getStatistics($request->start_date, $request->end_date);

        $data = [
            'logs' => $logs,
            'statistics' => $statistics,
            'filters' => [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'user_id' => $request->user_id,
                'action' => $request->action,
                'model_type' => $request->model_type
            ]
        ];

        // Handle export
        if ($request->export_format) {
            return $this->exportReport($data, $request->export_format);
        }

        return response()->json($data);
    }

    public function getSecurityReport()
    {
        $startDate = now()->subDays(30)->startOfDay();
        $endDate = now()->endOfDay();

        $securityEvents = AuditLog::query()
            ->with('user:id,name')
            ->where('action', 'like', 'security.%')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderByDesc('created_at')
            ->get();

        $failedLogins = AuditLog::query()
            ->with('user:id,name')
            ->where('action', 'auth.login_failed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $suspiciousActivities = AuditLog::query()
            ->with('user:id,name')
            ->where('action', 'security.suspicious_activity')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'security_events' => $securityEvents,
            'failed_logins' => $failedLogins,
            'suspicious_activities' => $suspiciousActivities,
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString()
            ]
        ]);
    }

    public function getUserActivity($userId)
    {
        $user = \App\Models\User::findOrFail($userId);
        
        $activities = AuditLog::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->groupBy(function ($item) {
                return $item->created_at->format('Y-m-d');
            });

        return response()->json([
            'user' => $user,
            'activities' => $activities,
            'total_activities' => AuditLog::where('user_id', $userId)->count()
        ]);
    }

    public function getComplianceReport()
    {
        $startDate = now()->subDays(90)->startOfDay();
        $endDate = now()->endOfDay();

        // Loan approval compliance
        $loanApprovals = AuditLog::query()
            ->with('user:id,name')
            ->where('action', 'like', 'approval.%')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        // High-value loan escalations
        $escalations = AuditLog::query()
            ->with('user:id,name')
            ->where('action', 'loan.escalated_to_ceo')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        // Data access logs
        $dataAccess = AuditLog::query()
            ->with('user:id,name')
            ->where('action', 'like', 'data.%')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        // Failed operations
        $failedOperations = AuditLog::query()
            ->with('user:id,name')
            ->where('action', 'like', '%failed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        return response()->json([
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString()
            ],
            'loan_approvals' => $loanApprovals,
            'escalations' => $escalations,
            'data_access' => $dataAccess,
            'failed_operations' => $failedOperations,
            'summary' => [
                'total_approvals' => $loanApprovals->count(),
                'total_escalations' => $escalations->count(),
                'total_data_access' => $dataAccess->count(),
                'total_failures' => $failedOperations->count()
            ]
        ]);
    }

    public function exportReport($data, $format)
    {
        switch ($format) {
            case 'csv':
                return $this->exportToCSV($data);
            case 'excel':
                return $this->exportToExcel($data);
            case 'json':
            default:
                return response()->json($data);
        }
    }

    private function exportToCSV($data)
    {
        $csv = "Timestamp,User,Action,Model Type,Model ID,IP Address,User Agent\n";
        
        foreach ($data['logs'] as $log) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                $log->created_at,
                $log->user ? $log->user->name : 'System',
                $log->action,
                $log->model_type ?? 'N/A',
                $log->model_id ?? 'N/A',
                $log->ip_address,
                str_replace(["\n", "\r", ","], " ", $log->user_agent)
            );
        }

        $response = response($csv);
        $response->header('Content-Type', 'text/csv');
        $response->header('Content-Disposition', 'attachment; filename="audit_report.csv"');
        
        return $response;
    }

    private function exportToExcel($data)
    {
        // For Excel export, you would typically use a library like Laravel Excel
        // For now, returning CSV format
        return $this->exportToCSV($data);
    }

    public function cleanupOldLogs(Request $request)
    {
        $request->validate([
            'days_to_keep' => 'required|integer|min:30|max:1095'
        ]);

        $deletedCount = AuditLogService::cleanup($request->days_to_keep);

        return response()->json([
            'success' => true,
            'deleted_count' => $deletedCount,
            'message' => "Successfully deleted {$deletedCount} old audit logs."
        ]);
    }

    public function getAuditStatistics()
    {
        $last30Days = AuditLogService::getStatistics(
            now()->subDays(30)->startOfDay(),
            now()->endOfDay()
        );

        $last90Days = AuditLogService::getStatistics(
            now()->subDays(90)->startOfDay(),
            now()->endOfDay()
        );

        return response()->json([
            'last_30_days' => $last30Days,
            'last_90_days' => $last90Days,
            'comparison' => [
                'growth_rate' => $this->calculateGrowthRate($last90Days, $last30Days),
                'trend' => $this->calculateTrend($last30Days)
            ]
        ]);
    }

    private function calculateGrowthRate($olderData, $newerData)
    {
        if ($olderData['total_logs'] == 0) {
            return 0;
        }

        $growth = (($newerData['total_logs'] - $olderData['total_logs']) / $olderData['total_logs']) * 100;
        return round($growth, 2);
    }

    private function calculateTrend($data)
    {
        // Simple trend calculation based on recent activity
        $totalLogs = $data['total_logs'];
        $securityEvents = $data['security_events'];
        $failedLogins = $data['failed_logins'];

        if ($securityEvents > $totalLogs * 0.1) {
            return 'high_risk';
        } elseif ($failedLogins > $totalLogs * 0.05) {
            return 'medium_risk';
        }

        return 'normal';
    }
}
