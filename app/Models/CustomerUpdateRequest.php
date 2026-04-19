<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerUpdateRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'requested_by',
        'reviewed_by',
        'field_name',
        'current_value',
        'requested_value',
        'explanation',
        'supporting_document_name',
        'supporting_document_path',
        'status',
        'review_note',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
