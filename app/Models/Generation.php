<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Generation extends Model
{
    protected $fillable = [
        'type',
        'prompt',
        'text_provider',
        'image_provider',
        'options',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'content' => 'array',
        ];
    }
}
