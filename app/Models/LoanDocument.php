<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_id',
        'uploaded_by',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
