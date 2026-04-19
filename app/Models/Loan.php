<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'loan_product',
        'requested_amount',
        'term_months',
        'interest_rate',
        'application_date',
        'repayment_frequency',
        'purpose',
        'status',
        'requires_manager_approval',
        'approval_role',
        'next_due_date',
        'approved_by',
        'created_by',
        'approval_note',
        'rejection_reason',
        'rejected_by',
        'approved_at',
        'rejected_at',
        'closed_at',
        'closed_by',
        'closure_reason',
        'closing_balance',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'application_date' => 'date',
        'requires_manager_approval' => 'boolean',
        'approval_role' => 'string',
        'next_due_date' => 'date',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'closed_at' => 'datetime',
        'closing_balance' => 'decimal:2',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function repayments()
    {
        return $this->hasMany(Repayment::class);
    }

    public function schedules()
    {
        return $this->hasMany(LoanPaymentSchedule::class);
    }

    public function documents()
    {
        return $this->hasMany(LoanDocument::class);
    }

    public function statementRequests()
    {
        return $this->hasMany(LoanStatementRequest::class);
    }

    public function escalations()
    {
        return $this->hasMany(LoanEscalation::class);
    }

    public function latestEscalation()
    {
        return $this->escalations()->latest()->first();
    }
}
