<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $normalized_name
 * @property ?string $tax_id
 * @property ?string $tax_key
 * @property ?string $email
 * @property string $default_currency
 * @property ?string $erp_ref
 * @property ?list<string> $aliases
 * @property ?list<string> $alias_keys
 * @property string $source
 */
class Vendor extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'name', 'tax_id', 'email', 'default_currency', 'erp_ref', 'aliases', 'source'];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'alias_keys' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (Vendor $v) {
            $v->normalized_name = self::normalize($v->name);
            $v->tax_key = self::taxKey($v->tax_id);
            $aliases = collect($v->aliases ?? [])->map(fn ($a) => trim((string) $a))->filter()->unique(fn ($a) => self::normalize($a))->values();
            $v->aliases = $aliases->all();
            $v->alias_keys = $aliases->map(fn ($a) => self::normalize($a))->filter()->unique()->values()->all();
        });
    }

    public static function normalize(?string $name): string
    {
        $n = Str::of((string) $name)->lower()->ascii()
            ->replace('&', ' and ')
            ->replace('.', '')                       // "L.L.C." -> "LLC", "Inc." -> "Inc"
            ->replaceMatches('/\b(inc|llc|ltd|limited|gmbh|co|corp|corporation|company|plc|bv|sa)\b\.?/', '')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();

        return (string) $n;
    }

    /** "DE 123-456-789" -> "DE123456789"; null when nothing is left. */
    public static function taxKey(?string $taxId): ?string
    {
        $k = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $taxId));

        return strlen($k) >= 4 ? $k : null;
    }

    /** Every normalised name this vendor goes by. @return list<string> */
    public function nameKeys(): array
    {
        return array_values(array_unique(array_filter([$this->normalized_name, ...($this->alias_keys ?? [])])));
    }

    public function addAlias(string $alias): bool
    {
        $key = self::normalize($alias);
        if ($key === '' || in_array($key, $this->nameKeys(), true)) {
            return false;
        }
        $this->aliases = [...($this->aliases ?? []), trim($alias)];
        $this->save();

        return true;
    }

    /** @return HasMany<VendorHint, $this> */
    public function hints(): HasMany
    {
        return $this->hasMany(VendorHint::class)->latest('id');
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
