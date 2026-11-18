<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Settings\ApprovalLimits;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** People in the workspace: for assigning reviews, and (admins) roles and approval limits. */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $limits = ApprovalLimits::for($request->user()->company_id);
        $users = User::where('company_id', $request->user()->company_id)->orderBy('name')->get();

        return response()->json(['data' => $users->map(fn (User $u) => $this->resource($u, $limits))]);
    }

    public function update(Request $request, User $user)
    {
        abort_unless($user->company_id === $request->user()->company_id, 404);
        $data = $request->validate([
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'approval_limit' => 'sometimes|nullable|numeric|min:0|max:1000000000',
        ]);
        if (isset($data['role']) && $data['role'] !== UserRole::Admin->value && $user->role === UserRole::Admin
            && User::where('company_id', $user->company_id)->where('role', UserRole::Admin->value)->count() === 1) {
            throw ValidationException::withMessages(['role' => 'A workspace needs at least one admin.']);
        }
        $user->update($data);

        return response()->json(['data' => $this->resource($user->fresh(), ApprovalLimits::for($user->company_id))]);
    }

    private function resource(User $u, ApprovalLimits $limits): array
    {
        return [
            'type' => 'users',
            'id' => (string) $u->id,
            'attributes' => [
                'name' => $u->name, 'email' => $u->email, 'role' => $u->role->value, 'can_review' => $u->role->canReview(),
                'approval_limit' => $u->approval_limit !== null ? (float) $u->approval_limit : null,
                'is_demo' => (bool) $u->is_demo,
                'effective_approval_limit' => $limits->limitFor($u),
            ],
        ];
    }
}
