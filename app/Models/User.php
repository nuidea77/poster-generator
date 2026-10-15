<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// Хэрэглэгч: брэнд, лого, захиалга, төлбөр, бүтээлүүд.
#[Fillable(['name', 'email', 'password', 'brand_name', 'brand_colors', 'brand_fonts', 'brand_tone', 'brand_notes', 'brand_logo_path'])]
#[Hidden(['password', 'remember_token', 'is_admin'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Баганын төрөл хувиргалт; нууц үгийг автоматаар hash хийнэ.
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'brand_colors' => 'array',
        ];
    }

    /**
     * Агентэд өгөх брэндийн багцын товч (англи) тайлбар; юу ч тохируулаагүй бол null.
     */
    public function brandBrief(): ?string
    {
        $parts = array_filter([
            'name' => $this->brand_name,
            'colours' => $this->brand_colors ? implode(', ', $this->brand_colors) : null,
            'fonts' => $this->brand_fonts,
            'tone' => $this->brand_tone,
            'notes' => $this->brand_notes ? preg_replace('/\s+/u', ' ', trim($this->brand_notes)) : null,
        ], 'filled');

        if (! $parts && ! $this->brand_logo_path) {
            return null;
        }

        $brief = 'Brand kit — '.implode('; ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($parts), $parts));

        if ($this->brand_logo_path) {
            $brief .= ($parts ? '; ' : '').'a saved logo is attached as the logo input';
        }

        return $brief.'.';
    }

    // Бүх захиалгын үеүд (үнэгүй олголтыг оруулаад).
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // Хэрэглэгчийн төлбөрүүд.
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // Хэрэглэгчийн бүтээлүүд.
    public function creations(): HasMany
    {
        return $this->hasMany(Creation::class);
    }

    /**
     * Одоо хүчинтэй төлбөртэй захиалгын үе (эсвэл хамгийн сүүлд дуусах ирээдүйн үе).
     */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()->paid()
            ->with('plan')
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();
    }

    // Төлбөртэй захиалга идэвхтэй эсэх; админ үргэлж идэвхтэй тооцогдоно.
    public function isSubscribed(): bool
    {
        return $this->is_admin || $this->subscriptions()->paid()->where('ends_at', '>', now())->exists();
    }
}
