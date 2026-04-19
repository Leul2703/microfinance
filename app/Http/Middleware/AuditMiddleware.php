<?php

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only log authenticated user actions
        if (Auth::check() && $this->shouldLogRequest($request)) {
            $this->logRequest($request, $response);
        }

        return $response;
    }

    /**
     * Determine if the request should be logged
     */
    private function shouldLogRequest(Request $request)
    {
        // Don't log GET requests to static pages
        if ($request->isMethod('GET') && $this->isStaticPageRequest($request)) {
            return false;
        }

        // Don't log asset requests
        if ($this->isAssetRequest($request)) {
            return false;
        }

        // Don't log AJAX requests that are already logged elsewhere
        if ($request->ajax() && $this->isAlreadyLoggedRequest($request)) {
            return false;
        }

        // Don't log health checks and similar requests
        if ($this->isSystemRequest($request)) {
            return false;
        }

        return true;
    }

    /**
     * Check if it's a static page request
     */
    private function isStaticPageRequest(Request $request)
    {
        $staticRoutes = [
            'dashboard',
            'login',
            'home',
            'customer.dashboard',
            'manager.loans.approvals',
            'staff.loans.create',
            'staff.savings.dashboard'
        ];

        return in_array($request->route()->getName(), $staticRoutes);
    }

    /**
     * Check if it's an asset request
     */
    private function isAssetRequest(Request $request)
    {
        $path = $request->path();
        
        return strpos($path, 'css') !== false || 
               strpos($path, 'js') !== false || 
               strpos($path, 'images') !== false || 
               strpos($path, 'fonts') !== false;
    }

    /**
     * Check if request is already logged by specific controllers
     */
    private function isAlreadyLoggedRequest(Request $request)
    {
        $alreadyLoggedRoutes = [
            'payments.process',
            'loans.approve',
            'loans.decline',
            'customers.store',
            'repayments.store'
        ];

        return in_array($request->route()->getName(), $alreadyLoggedRoutes);
    }

    /**
     * Check if it's a system request
     */
    private function isSystemRequest(Request $request)
    {
        $systemRoutes = [
            'api.health',
            'metrics',
            'ping'
        ];

        return in_array($request->route()->getName(), $systemRoutes);
    }

    /**
     * Log the request
     */
    private function logRequest(Request $request, $response)
    {
        $action = $this->getActionName($request);
        $data = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'route_name' => $request->route()->getName(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'response_status' => $response->getStatusCode(),
            'timestamp' => now()->toISOString()
        ];

        // Add request data for non-GET requests
        if (!$request->isMethod('GET')) {
            $requestData = $request->all();
            
            // Remove sensitive data from logs
            $sensitiveFields = ['password', 'password_confirmation', 'api_key', 'secret'];
            foreach ($sensitiveFields as $field) {
                if (isset($requestData[$field])) {
                    $requestData[$field] = '[FILTERED]';
                }
            }
            
            $data['request_data'] = $requestData;
        }

        AuditLogService::log($action, null, $data);
    }

    /**
     * Get action name for the request
     */
    private function getActionName(Request $request)
    {
        $routeName = $request->route()->getName();
        $method = strtolower($request->method());

        if ($routeName) {
            return "request.{$method}.{$routeName}";
        }

        // Fallback to URL-based action
        $path = $request->path();
        return "request.{$method}.{$path}";
    }
}
