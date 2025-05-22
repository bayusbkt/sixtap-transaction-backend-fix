<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RfidCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'card_uid',
        'is_active',
        'activated_at',
        'blocked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'activated_at' => 'datetime',
        'blocked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class, 'rfidcard_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'rfidcard_id');
    }
}
