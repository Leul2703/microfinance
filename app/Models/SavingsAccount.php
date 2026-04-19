<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavingsAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_number',
        'customer_id',
        'branch_id',
        'created_by',
        'manager_id',
        'status',
        'savings_type',
        'interest_rate',
        'term_months',
        'opened_at',
        'maturity_date',
        'approved_by',
        'approved_at',
        'approval_notes',
        'last_interest_applied_at',
        'closed_at',
        'closed_by',
        'closure_reason',
        'closing_balance',
    ];

    protected $casts = [
        'opened_at' => 'date',
        'maturity_date' => 'date',
        'approved_at' => 'datetime',
        'last_interest_applied_at' => 'date',
        'closed_at' => 'datetime',
        'closing_balance' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function transactions()
    {
        return $this->hasMany(SavingsTransaction::class);
    }
}
