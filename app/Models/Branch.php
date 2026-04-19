<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'max_deposit_limit',
        'min_deposit_amount',
        'max_withdrawal_limit',
        'daily_transaction_limit',
        'deposit_limits_enabled',
    ];

    protected $casts = [
        'max_deposit_limit' => 'decimal:2',
        'min_deposit_amount' => 'decimal:2',
        'max_withdrawal_limit' => 'decimal:2',
        'daily_transaction_limit' => 'decimal:2',
        'deposit_limits_enabled' => 'boolean',
    ];

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function loans()
    {
        return $this->hasManyThrough(Loan::class, Customer::class);
    }

    /**
     * Check if a deposit amount is within limits
     */
    public function isDepositWithinLimits($amount)
    {
        if (!$this->deposit_limits_enabled) {
            return true;
        }

        return $amount >= $this->min_deposit_amount && $amount <= $this->max_deposit_limit;
    }

    /**
     * Check if a withdrawal amount is within limits
     */
    public function isWithdrawalWithinLimits($amount)
    {
        if (!$this->deposit_limits_enabled) {
            return true;
        }

        return $amount <= $this->max_withdrawal_limit;
    }

    /**
     * Check if daily transaction limit is exceeded
     */
    public function isDailyLimitExceeded($currentDailyAmount, $newTransactionAmount)
    {
        if (!$this->deposit_limits_enabled) {
            return false;
        }

        return ($currentDailyAmount + $newTransactionAmount) > $this->daily_transaction_limit;
    }
}
