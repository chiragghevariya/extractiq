<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\GrnLine;
use Illuminate\Http\Request;

class GoodsReceiptController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['filter.q' => 'nullable|string|max:100', 'filter.purchase_order' => 'nullable|integer']);
        $query = GoodsReceipt::query()->with('purchaseOrder:id,po_number,vendor_id', 'purchaseOrder.vendor:id,name', 'lines')->latest('received_date')->latest('id');
        if ($q = $request->input('filter.q')) {
            $like = '%'.mb_strtolower($q).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(grn_number) like ?', [$like])->orWhereHas('purchaseOrder', fn ($p) => $p->whereRaw('lower(po_number) like ?', [$like])));
        }
        if ($po = $request->input('filter.purchase_order')) {
            $query->where('purchase_order_id', $po);
        }
        $page = $query->paginate((int) $request->input('page.size', 25), ['*'], 'page[number]', (int) $request->input('page.number', 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (GoodsReceipt $g) => [
                'type' => 'goods-receipts', 'id' => (string) $g->id, 'attributes' => ['number' => $g->grn_number, 'received_date' => $g->received_date?->toDateString(),
                'source' => $g->source, 'source_document_id' => $g->source_document_id ? (string) $g->source_document_id : null,
                'purchase_order' => $g->purchaseOrder ? ['id' => (string) $g->purchaseOrder->id, 'number' => $g->purchaseOrder->po_number] : null,
                'vendor_name' => $g->purchaseOrder?->vendor?->name,
                'line_count' => $g->lines->count(),
                'unlinked_lines' => $g->lines->filter(fn (GrnLine $l) => ! $l->po_line_id)->count(),
                'quantity' => $g->lines->sum('quantity'),
            ]])->values(),
            'meta' => ['page' => ['current' => $page->currentPage(), 'size' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()]],
        ]);
    }
}
