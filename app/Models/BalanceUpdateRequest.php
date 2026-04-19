<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BalanceUpdateRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'account_type',
        'account_id',
        'current_balance',
        'requested_balance',
        'adjustment_amount',
        'reason',
        'supporting_document',
        'status',
        'reviewed_by',
        'review_note',
        'reviewed_at',
        'created_by',
    ];

    protected $casts = [
        'current_balance' => 'decimal:2',
        'requested_balance' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class, 'account_id')->where('account_type', 'loan');
    }

    public function savingsAccount()
    {
        return $this->belongsTo(SavingsAccount::class, 'account_id')->where('account_type', 'savings');
    }

    // Scopes for different statuses
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}
