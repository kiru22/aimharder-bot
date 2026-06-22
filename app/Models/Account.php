<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = ['label', 'email', 'password', 'fingerprint', 'subdomain', 'box_id', 'active'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'box_id' => 'integer', 'active' => 'boolean'];
    }

    protected $attributes = ['subdomain' => 'hybridboxgrau', 'box_id' => 8244, 'active' => true];

    protected static function booted(): void
    {
        static::creating(function (Account $a) {
            if (empty($a->fingerprint)) {
                $a->fingerprint = substr(hash('sha256', 'aimharder-bot-'.$a->email), 0, 50);
            }
        });
    }

    public function rules(): HasMany
    {
        return $this->hasMany(BookingRule::class);
    }
}
