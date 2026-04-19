<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function branchIdForUser(?User $user = null): ?int
    {
        $user = $user ?: Auth::user();
        if (!$user) {
            return null;
        }

        if ($user->branch_id) {
            return (int) $user->branch_id;
        }

        return $user->manager && $user->manager->branch_id
            ? (int) $user->manager->branch_id
            : null;
    }

    protected function logAudit(string $action, $auditable = null, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable && isset($auditable->id) ? $auditable->id : null,
            'metadata' => $metadata,
        ]);
    }
}
