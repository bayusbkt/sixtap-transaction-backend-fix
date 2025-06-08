<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'rfid_card_id',
        'canteen_id',
        'type',
        'status',
        'amount',
        'user_id',
        'note'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    protected function serializeDate(\DateTimeInterface $date)
    {
        return Carbon::instance($date)
            ->timezone('Asia/Jakarta')
            ->format('Y-m-d H:i:s');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rfidCard()
    {
        return $this->belongsTo(RfidCard::class, 'rfid_card_id');
    }

    public function canteen()
    {
        return $this->belongsTo(Canteen::class, 'canteen_id');
    }
}
