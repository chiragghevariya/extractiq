<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Matching\MatchSettings;
use App\Services\Settings\ApprovalLimits;
use App\Services\Settings\ProviderSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function matching(Request $request)
    {
        return response()->json(['data' => MatchSettings::for($request->user()->company_id)->toArray(), 'meta' => ['defaults' => MatchSettings::defaults()]]);
    }

    public function updateMatching(Request $request)
    {
        $data = $request->validate([
            'price_tolerance_pct' => 'sometimes|numeric|min:0|max:100',
            'price_tolerance_abs' => 'sometimes|numeric|min:0|max:1000000',
            'qty_tolerance_pct' => 'sometimes|numeric|min:0|max:100',
            'total_tolerance_abs' => 'sometimes|numeric|min:0|max:1000000',
            'mode' => ['sometimes', Rule::in(MatchSettings::MODES)],
            'accept_role' => ['sometimes', Rule::in(MatchSettings::ROLES)],
        ]);
        $company = $request->user()->company;
        $settings = $company->settings ?? [];
        $settings['matching'] = array_merge(MatchSettings::for($company)->toArray(), $data);
        $company->update(['settings' => $settings]);

        return $this->matching($request);
    }

    /** OCR and LLM driver for this company; `available` says which ones this server can run. */
    public function providers(Request $request)
    {
        $llm = ProviderSettings::for($request->user()->company_id);

        return response()->json(['data' => $llm->toArray(), 'meta' => [
            'available' => ['ocr' => ProviderSettings::catalog('ocr'), 'llm' => ProviderSettings::catalog('llm')],
            'defaults' => ['ocr' => config('extractiq.ocr.default'), 'llm' => config('extractiq.llm.default')],
            'models' => collect(array_keys((array) config('extractiq.llm.drivers')))->mapWithKeys(fn ($d) => [$d => config("extractiq.llm.drivers.$d.model")])->filter()->all(),
        ]]);
    }

    public function updateProviders(Request $request)
    {
        $data = $request->validate([
            'ocr' => ['sometimes', 'string', Rule::in(ProviderSettings::usable('ocr'))],
            'llm' => ['sometimes', 'string', Rule::in(ProviderSettings::usable('llm'))],
        ], ['in' => 'That provider is not configured on this server (missing API key).']);
        $company = $request->user()->company;
        $settings = $company->settings ?? [];
        $settings['providers'] = array_merge(ProviderSettings::for($company)->toArray(), $data);
        $company->update(['settings' => $settings]);

        return $this->providers($request);
    }

    /** Approval limits per role (amount in the invoice's currency; null = no limit). */
    public function approvals(Request $request)
    {
        return response()->json(['data' => ApprovalLimits::for($request->user()->company_id)->toArray(), 'meta' => ['defaults' => ApprovalLimits::DEFAULTS]]);
    }

    public function updateApprovals(Request $request)
    {
        $data = $request->validate([
            'admin' => 'sometimes|nullable|numeric|min:0|max:1000000000',
            'reviewer' => 'sometimes|nullable|numeric|min:0|max:1000000000',
        ]);
        $company = $request->user()->company;
        $settings = $company->settings ?? [];
        $settings['approvals'] = array_merge(ApprovalLimits::for($company)->toArray(), array_map(fn ($v) => $v === null ? null : (float) $v, $data));
        $company->update(['settings' => $settings]);

        return $this->approvals($request);
    }
}
