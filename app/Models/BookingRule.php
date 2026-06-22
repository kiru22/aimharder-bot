<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingRule extends Model
{
    protected $fillable = ['account_id', 'weekdays', 'time', 'class_name', 'insist', 'active'];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'insist' => 'boolean', 'active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
