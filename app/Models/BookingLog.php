<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['account_id', 'booking_rule_id', 'target_date', 'class_id', 'status', 'book_state', 'message', 'created_at'];

    protected function casts(): array
    {
        return ['target_date' => 'date', 'created_at' => 'datetime', 'book_state' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (BookingLog $l) => $l->created_at ??= now());
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(BookingRule::class, 'booking_rule_id');
    }
}
