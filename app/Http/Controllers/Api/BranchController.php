<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;

class BranchController extends Controller
{
    public function index()
    {
        $user = request()->user();
        $branchId = $this->branchIdForUser($user);

        return Branch::query()
            ->when($user && $user->role !== 'admin' && $branchId, function ($query) use ($branchId) {
                $query->where('id', $branchId);
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
