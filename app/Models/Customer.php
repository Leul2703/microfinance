<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'full_name',
        'national_id',
        'phone_number',
        'email_address',
        'date_of_birth',
        'gender',
        'is_woman',
        'has_disability',
        'disability_type',
        'education_level',
        'marital_status',
        'dependents_count',
        'employment_status',
        'monthly_income',
        'occupation',
        'address',
        'branch_id',
        'registration_date',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'registration_date' => 'date',
        'is_woman' => 'boolean',
        'has_disability' => 'boolean',
        'dependents_count' => 'integer',
        'monthly_income' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function loans()
    {
        return $this->hasMany(Loan::class);
    }

    public function savingsAccounts()
    {
        return $this->hasMany(SavingsAccount::class);
    }

    public function updateRequests()
    {
        return $this->hasMany(CustomerUpdateRequest::class);
    }

    // Scopes for inclusion reporting
    public function scopeWomen($query)
    {
        return $query->where('is_woman', true);
    }

    public function scopePersonsDisability($query)
    {
        return $query->where('has_disability', true);
    }

    public function scopeByGender($query, $gender)
    {
        return $query->where('gender', $gender);
    }

    public function scopeByEducationLevel($query, $level)
    {
        return $query->where('education_level', $level);
    }

    public function scopeByEmploymentStatus($query, $status)
    {
        return $query->where('employment_status', $status);
    }

    public function scopeIncomeRange($query, $min, $max = null)
    {
        $query->where('monthly_income', '>=', $min);
        if ($max) {
            $query->where('monthly_income', '<=', $max);
        }
        return $query;
    }

    // Accessors for computed properties
    public function getInclusionCategoryAttribute()
    {
        if ($this->is_woman && $this->has_disability) {
            return 'Woman with Disability';
        } elseif ($this->is_woman) {
            return 'Woman';
        } elseif ($this->has_disability) {
            return 'Person with Disability';
        }
        return 'General';
    }

    public function getAgeAttribute()
    {
        return $this->date_of_birth ? $this->date_of_birth->age : null;
    }
}
