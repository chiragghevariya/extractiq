<?php

namespace App\Services\Matching;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Support\Money;

/**
 * Tolerances for one company: config defaults overlaid with companies.settings.matching.
 * Absolute amounts are kept in major units and converted per currency when used.
 */
final class MatchSettings
{
    public const MODES = ['auto', 'two_way', 'three_way'];

    public const ROLES = ['admin', 'reviewer'];

    public function __construct(
        public readonly float $pricePct,
        public readonly float $priceAbs,
        public readonly float $qtyPct,
        public readonly float $totalAbs,
        public readonly string $mode,
        public readonly string $acceptRole,
    ) {}

    public static function defaults(): array
    {
        return config('extractiq.matching');
    }

    public static function for(Company|int $company): self
    {
        $company = $company instanceof Company ? $company : Company::findOrFail($company);
        $s = array_merge(self::defaults(), (array) ($company->settings['matching'] ?? []));

        return new self((float) $s['price_tolerance_pct'], (float) $s['price_tolerance_abs'], (float) $s['qty_tolerance_pct'],
            (float) $s['total_tolerance_abs'], in_array($s['mode'], self::MODES, true) ? $s['mode'] : 'auto',
            in_array($s['accept_role'], self::ROLES, true) ? $s['accept_role'] : 'admin');
    }

    public function toArray(): array
    {
        return [
            'price_tolerance_pct' => $this->pricePct, 'price_tolerance_abs' => $this->priceAbs, 'qty_tolerance_pct' => $this->qtyPct,
            'total_tolerance_abs' => $this->totalAbs, 'mode' => $this->mode, 'accept_role' => $this->acceptRole,
        ];
    }

    /** Largest allowed unit-price difference (minor units) against a PO price. */
    public function priceAllowance(int $poPriceMinor, ?string $currency): int
    {
        return (int) max(round(abs($poPriceMinor) * $this->pricePct / 100), Money::toMinor($this->priceAbs, $currency) ?? 0);
    }

    public function qtyLimit(float $base): float
    {
        return $base * (1 + $this->qtyPct / 100);
    }

    public function totalAllowance(?string $currency): int
    {
        return Money::toMinor($this->totalAbs, $currency) ?? 0;
    }

    public function canAccept(User $user): bool
    {
        return $user->role === UserRole::Admin || ($this->acceptRole === 'reviewer' && $user->role === UserRole::Reviewer);
    }
}
