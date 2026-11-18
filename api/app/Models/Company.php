<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $base_currency
 * @property string $inbound_email
 * @property ?array<string, mixed> $settings
 */
class Company extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'base_currency', 'inbound_email', 'settings'];

    protected static function booted(): void
    {
        static::creating(function (Company $c) {
            $c->inbound_email ??= self::inboxFor($c->slug);
        });
    }

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** Every company gets an inbox: ap-{slug}@inbound.extractiq.test (domain configurable). */
    public static function inboxFor(string $slug): string
    {
        return strtolower('ap-'.$slug.'@'.config('extractiq.inbound_email.domain'));
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}
