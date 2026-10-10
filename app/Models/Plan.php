<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['slug', 'name', 'price', 'period_days', 'features', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'period_days' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
