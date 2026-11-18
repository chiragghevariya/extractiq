<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Settings\ApprovalLimits;
use Illuminate\Http\Request;

/** @mixin User */
class UserResource extends JsonApiResource
{
    public function type(): string
    {
        return 'users';
    }

    public function fields(Request $request): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            // What this person may approve (invoice currency; null = no limit).
            'approval_limit' => ApprovalLimits::for($this->company_id)->limitFor($this->resource),
            'is_demo' => (bool) $this->is_demo,
            'company' => ['id' => (string) $this->company_id, 'name' => $this->company->name, 'base_currency' => $this->company->base_currency, 'inbound_email' => $this->company->inbound_email],
        ];
    }
}
