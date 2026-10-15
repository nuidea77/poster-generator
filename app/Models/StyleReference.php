<?php

namespace App\Models;

use App\Services\Media\MediaStore;
use Illuminate\Database\Eloquent\Model;

// Админы оруулсан хэв маягийн жишээ зураг (гэрэл, өнгө, найруулгыг зээлэх, текст/логог биш).
class StyleReference extends Model
{
    // Зөвшөөрөгдсөн ангиллууд.
    public const CATEGORIES = ['food', 'beauty', 'fashion', 'health', 'education', 'event', 'retail', 'tech', 'real_estate', 'sport', 'other'];

    protected $fillable = ['title', 'category', 'style', 'tags', 'notes', 'path', 'enabled'];

    protected $appends = ['url'];

    // Баганын төрөл хувиргалт.
    protected function casts(): array
    {
        return ['tags' => 'array', 'enabled' => 'boolean'];
    }

    // SPA-д харуулах харьцангуй URL.
    public function getUrlAttribute(): string
    {
        return MediaStore::url($this->path);
    }
}
