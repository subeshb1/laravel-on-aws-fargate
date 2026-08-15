<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $fillable = ['title', 'status', 's3_key', 'rows', 'completed_at', 'worker'];

    protected $casts = [
        'completed_at' => 'datetime',
        'rows' => 'integer',
    ];
}
