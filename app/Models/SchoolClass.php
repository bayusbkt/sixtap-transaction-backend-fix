<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SchoolClass extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_name',
        'class_code',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'schoolclass_id');
    }
}
