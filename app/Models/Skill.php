<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $fillable = ['name', 'description', 'origin', 'content', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
