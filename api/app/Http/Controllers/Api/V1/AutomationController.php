<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Pipeline\AutomationSettings;
use Illuminate\Http\Request;

/** Touchless processing: skip review for small validated invoices; push if they match. */
class AutomationController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['data' => AutomationSettings::for($request->user()->company_id)->toArray()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['touchless' => 'sometimes|boolean', 'touchless_max_total' => 'sometimes|numeric|min:0|max:100000000']);
        $company = $request->user()->company;
        $settings = $company->settings ?? [];
        $settings['automation'] = array_merge(AutomationSettings::for($company)->toArray(), $data);
        $company->update(['settings' => $settings]);

        return $this->show($request);
    }
}
