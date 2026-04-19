<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Repayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_id',
        'installment_amount',
        'payment_method',
        'payment_date',
        'payment_type',
        'payment_note',
        'installments_covered',
        'excess_amount',
        'created_by',
    ];

    protected $casts = [
        'installment_amount' => 'decimal:2',
        'excess_amount' => 'decimal:2',
        'payment_date' => 'date',
        'installments_covered' => 'array',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function documents()
    {
        return $this->hasMany(RepaymentDocument::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes for different payment types
    public function scopeRegular($query)
    {
        return $query->where('payment_type', 'regular');
    }

    public function scopeAdvance($query)
    {
        return $query->where('payment_type', 'advance');
    }

    public function scopeBulk($query)
    {
        return $query->where('payment_type', 'bulk');
    }
}
