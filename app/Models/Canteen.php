<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Canteen extends Model
{
    use HasFactory;

    protected $fillable = [
        'initial_balance',
        'current_balance',
        'is_settled',
        'settlement_time',
    ];

    protected $casts = [
        'initial_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_settled' => 'boolean',
        'settlement_time' => 'datetime',
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'canteen_id');
    }
}
