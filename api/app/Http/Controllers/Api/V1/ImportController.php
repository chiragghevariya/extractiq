<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Matching\CsvImporter;
use App\Services\Matching\Rematcher;
use Illuminate\Http\Request;

/** CSV import of master data (vendors, purchase orders, goods receipts). */
class ImportController extends Controller
{
    public function store(Request $request, CsvImporter $importer, Rematcher $rematcher, string $kind)
    {
        abort_unless(in_array($kind, CsvImporter::KINDS, true), 404);
        $request->validate(['file' => 'required|file|max:5120|mimes:csv,txt']);
        $companyId = $request->user()->company_id;

        $result = $importer->import($companyId, $kind, (string) file_get_contents($request->file('file')->getRealPath()));
        $result['rematched'] = $result['affected_po_ids'] ? $rematcher->forPurchaseOrders($companyId, $result['affected_po_ids']) : 0;
        unset($result['affected_po_ids']);

        // 200 whenever the file was read; the per-row report is in meta (a 422 means the request itself was bad).
        return response()->json(['meta' => $result]);
    }

    /** A header-only CSV to start from. */
    public function template(string $kind)
    {
        abort_unless(in_array($kind, CsvImporter::KINDS, true), 404);

        return response(implode(',', CsvImporter::TEMPLATES[$kind])."\n", 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$kind-template.csv\"",
        ]);
    }
}
