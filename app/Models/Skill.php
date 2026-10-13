<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Админы нэмсэн custom skill, эсвэл bundled skill-ийн DB override.
class Skill extends Model
{
    protected $fillable = ['name', 'description', 'origin', 'content', 'enabled'];

    // Баганын төрөл хувиргалт.
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
