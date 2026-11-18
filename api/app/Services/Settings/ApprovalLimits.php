<?php

namespace App\Services\Settings;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Approval limits by amount (Settings → People). Each role has a limit per invoice total; a user
 * can have their own limit that replaces the role's. null = no limit. Viewers can never approve.
 * Compared in the invoice's own currency (no FX conversion): 10,000 means USD 10,000 on a USD
 * invoice and EUR 10,000 on a EUR one. Automatic approvals (touchless) have their own cap.
 */
final class ApprovalLimits
{
    public const DEFAULTS = ['admin' => null, 'reviewer' => 10000.0];

    /** @param array{admin: ?float, reviewer: ?float} $roles */
    public function __construct(public readonly array $roles) {}

    public static function for(Company|int $company): self
    {
        $company = $company instanceof Company ? $company : Company::findOrFail($company);
        $s = (array) ($company->settings['approvals'] ?? []);
        $roles = [];
        foreach (self::DEFAULTS as $role => $default) {
            $roles[$role] = array_key_exists($role, $s) ? ($s[$role] === null ? null : (float) $s[$role]) : $default;
        }

        return new self($roles);
    }

    public function toArray(): array
    {
        return $this->roles;
    }

    /** The limit that applies to this user: 0 for viewers, the user's own, else their role's. */
    public function limitFor(User $user): ?float
    {
        if ($user->role === UserRole::Viewer) {
            return 0.0;
        }

        return $user->approval_limit !== null ? (float) $user->approval_limit : ($this->roles[$user->role->value] ?? null);
    }

    public function allows(User $user, Document $doc): bool
    {
        $limit = $this->limitFor($user);
        if ($limit === null || $doc->total_minor === null) {
            return $user->role !== UserRole::Viewer;
        }

        return $doc->total_minor <= (Money::toMinor($limit, $doc->currency ?? 'USD') ?? 0);
    }

    /** @throws AuthorizationException */
    public function authorize(User $user, Document $doc): void
    {
        if (! $this->allows($user, $doc)) {
            $limit = $this->limitFor($user);
            throw new AuthorizationException(sprintf(
                'This invoice is above your approval limit (%s %s). Someone with a higher limit needs to approve it.',
                $doc->currency ?? '', number_format((float) $limit, 2),
            ));
        }
    }
}
