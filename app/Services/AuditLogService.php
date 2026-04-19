<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    /**
     * Log user action
     */
    public static function log($action, $model = null, $data = [], $userId = null)
    {
        $logData = [
            'user_id' => $userId ?? (Auth::check() ? Auth::id() : null),
            'action' => $action,
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model ? $model->id : null,
            'data' => json_encode($data),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ];

        try {
            AuditLog::create($logData);
        } catch (\Exception $e) {
            \Log::error('Failed to create audit log', [
                'error' => $e->getMessage(),
                'log_data' => $logData
            ]);
        }
    }

    /**
     * Log authentication events
     */
    public static function logAuth($event, $user = null, $data = [])
    {
        self::log("auth.{$event}", $user, array_merge([
            'event_type' => 'authentication',
            'timestamp' => now()->toISOString()
        ], $data));
    }

    /**
     * Log loan operations
     */
    public static function logLoan($action, $loan, $data = [])
    {
        self::log("loan.{$action}", $loan, array_merge([
            'customer_id' => $loan->customer_id ?? null,
            'loan_amount' => $loan->requested_amount ?? null,
            'loan_status' => $loan->status ?? null
        ], $data));
    }

    /**
     * Log customer operations
     */
    public static function logCustomer($action, $customer, $data = [])
    {
        self::log("customer.{$action}", $customer, array_merge([
            'customer_name' => $customer->full_name ?? null,
            'national_id' => $customer->national_id ?? null,
            'branch_id' => $customer->branch_id ?? null
        ], $data));
    }

    /**
     * Log payment operations
     */
    public static function logPayment($action, $repayment, $data = [])
    {
        self::log("payment.{$action}", $repayment, array_merge([
            'payment_amount' => $repayment->amount ?? null,
            'payment_type' => $repayment->payment_type ?? null,
            'payment_method' => $repayment->payment_method ?? null,
            'loan_id' => $repayment->loan_id ?? null
        ], $data));
    }

    /**
     * Log approval operations
     */
    public static function logApproval($action, $model, $approverRole = null, $data = [])
    {
        self::log("approval.{$action}", $model, array_merge([
            'approver_role' => $approverRole,
            'approval_level' => self::getApprovalLevel($model),
            'timestamp' => now()->toISOString()
        ], $data));
    }

    /**
     * Log data changes
     */
    public static function logDataChange($action, $model, $oldValues = [], $newValues = [])
    {
        $changes = [];
        
        foreach ($oldValues as $key => $oldValue) {
            if (isset($newValues[$key]) && $oldValue !== $newValues[$key]) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValues[$key]
                ];
            }
        }

        self::log("data.{$action}", $model, [
            'changes' => $changes,
            'changed_fields' => array_keys($changes)
        ]);
    }

    /**
     * Log system events
     */
    public static function logSystem($event, $data = [])
    {
        self::log("system.{$event}", null, array_merge([
            'event_type' => 'system',
            'timestamp' => now()->toISOString(),
            'environment' => app()->environment()
        ], $data));
    }

    /**
     * Log security events
     */
    public static function logSecurity($event, $data = [])
    {
        self::log("security.{$event}", null, array_merge([
            'event_type' => 'security',
            'severity' => self::getSecuritySeverity($event),
            'timestamp' => now()->toISOString()
        ], $data));
    }

    /**
     * Log API access
     */
    public static function logApi($endpoint, $method, $userId = null, $data = [])
    {
        self::log("api.access", null, array_merge([
            'endpoint' => $endpoint,
            'method' => $method,
            'user_id' => $userId,
            'timestamp' => now()->toISOString()
        ], $data));
    }

    /**
     * Log file operations
     */
    public static function logFile($action, $fileName, $fileType = null, $data = [])
    {
        self::log("file.{$action}", null, array_merge([
            'file_name' => $fileName,
            'file_type' => $fileType,
            'timestamp' => now()->toISOString()
        ], $data));
    }

    /**
     * Get approval level for a model
     */
    private static function getApprovalLevel($model)
    {
        if (method_exists($model, 'getTable')) {
            $tableName = $model->getTable();
            
            switch ($tableName) {
                case 'loans':
                    return 'loan_approval';
                case 'savings_accounts':
                    return 'savings_approval';
                case 'customer_update_requests':
                    return 'customer_update_approval';
                case 'loan_escalations':
                    return 'ceo_approval';
                default:
                    return 'general_approval';
            }
        }
        
        return 'unknown';
    }

    /**
     * Get security severity level
     */
    private static function getSecuritySeverity($event)
    {
        $severityMap = [
            'login_failed' => 'medium',
            'login_blocked' => 'high',
            'unauthorized_access' => 'high',
            'privilege_escalation' => 'critical',
            'data_breach_attempt' => 'critical',
            'suspicious_activity' => 'medium',
            'password_change' => 'low',
            'account_locked' => 'high'
        ];

        return $severityMap[$event] ?? 'medium';
    }

    /**
     * Log batch operations
     */
    public static function logBatch($action, $items, $data = [])
    {
        self::log("batch.{$action}", null, array_merge([
            'batch_size' => count($items),
            'items_processed' => $items,
            'timestamp' => now()->toISOString()
        ], $data));
    }

    /**
     * Log configuration changes
     */
    public static function logConfig($key, $oldValue, $newValue, $context = [])
    {
        self::log("config.changed", null, [
            'config_key' => $key,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'context' => $context,
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Cleanup old audit logs (for maintenance)
     */
    public static function cleanup($daysToKeep = 365)
    {
        try {
            $deleted = AuditLog::where('created_at', '<', now()->subDays($daysToKeep))->delete();
            
            self::logSystem('audit_cleanup', [
                'deleted_records' => $deleted,
                'retention_period' => $daysToKeep
            ]);
            
            return $deleted;
        } catch (\Exception $e) {
            \Log::error('Failed to cleanup audit logs', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get audit statistics
     */
    public static function getStatistics($startDate = null, $endDate = null)
    {
        $query = AuditLog::query();

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        return [
            'total_logs' => $query->count(),
            'unique_users' => $query->distinct('user_id')->count('user_id'),
            'top_actions' => $query->select('action', \DB::raw('count(*) as count'))
                ->groupBy('action')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
            'security_events' => $query->where('action', 'like', 'security.%')
                ->count(),
            'failed_logins' => $query->where('action', 'auth.login_failed')
                ->count()
        ];
    }
}
