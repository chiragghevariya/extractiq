<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Erp\ErpManager;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function index(Request $request)
    {
        $llm = config('extractiq.llm.default');
        $company = $request->user()->company;

        return response()->json(['data' => [
            'type' => 'integrations',
            'id' => 'current',
            'attributes' => [
                'ocr' => ['driver' => config('extractiq.ocr.default'), 'available' => array_keys(config('extractiq.ocr.drivers'))],
                'llm' => [
                    'driver' => $llm,
                    'model' => config("extractiq.llm.drivers.$llm.model"),
                    'available' => array_keys(config('extractiq.llm.drivers')),
                    'configured' => $llm === 'heuristic' || filled(config("extractiq.llm.drivers.$llm.api_key")),
                ],
                'erp' => ['driver' => \App\Models\ErpConnection::activeFor($company->id)->connector, 'available' => \App\Models\ErpConnection::CONNECTORS],
                'inbound_email' => $company->inbound_email,
                'review_confidence_threshold' => config('extractiq.review_confidence_threshold'),
                'auto_approve_validated' => config('extractiq.pipeline.auto_approve_validated'),
                'matching' => \App\Services\Matching\MatchSettings::for($company)->toArray(),
            ],
        ]]);
    }

    public function testErp(Request $request, ErpManager $erp)
    {
        $connector = $erp->forCompany($request->user()->company_id);
        $result = $connector->testConnection();

        return response()->json(['data' => ['type' => 'connection-tests', 'id' => $connector->name(), 'attributes' => $result]], $result['ok'] ? 200 : 502);
    }
}
