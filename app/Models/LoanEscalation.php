<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanEscalation extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_id',
        'requested_by',
        'reviewed_by',
        'recommendation_note',
        'review_note',
        'status',
        'escalated_at',
        'reviewed_at',
    ];

    protected $casts = [
        'escalated_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // Scopes for different statuses
    public function scopePendingReview($query)
    {
        return $query->where('status', 'pending_ceo_review');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'ceo_approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'ceo_rejected');
    }
}
