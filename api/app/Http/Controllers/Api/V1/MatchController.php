<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\MatchResource;
use App\Jobs\MatchDocument;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\MatchLine;
use App\Services\Matching\MatchActions;
use App\Services\Matching\MatchSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MatchController extends Controller
{
    public function __construct(private readonly MatchActions $actions) {}

    /**
     * Current match per invoice. filter[result]: open (invoices in match_exception, default),
     * on_hold, credit_note (credit note requested), matched, accepted, exception (any).
     */
    public function index(Request $request)
    {
        $request->validate(['filter.result' => 'nullable|in:open,on_hold,credit_note,matched,accepted,exception', 'filter.q' => 'nullable|string|max:100']);
        $result = $request->input('filter.result', 'open');
        $q = DocumentMatch::query()->with('invoice', 'purchaseOrder.vendor', 'lines')->where('is_current', true)->latest('id');
        match ($result) {
            'open' => $q->whereHas('invoice', fn ($d) => $d->where('status', DocumentStatus::MatchException->value)->whereNull('on_hold_at')),
            'on_hold' => $q->whereHas('invoice', fn ($d) => $d->whereNotNull('on_hold_at')),
            'credit_note' => $q->whereNotNull('credit_note_requested_at')->whereHas('invoice', fn ($d) => $d->where('status', DocumentStatus::MatchException->value)),
            default => $q->where('result', $result),
        };
        if ($s = $request->input('filter.q')) {
            $like = '%'.mb_strtolower($s).'%';
            $q->whereHas('invoice', fn ($d) => $d->where(fn ($w) => $w->whereRaw('lower(document_number) like ?', [$like])->orWhereRaw('lower(vendor_name) like ?', [$like])));
        }

        $counts = fn (callable $scope) => $scope(DocumentMatch::query()->where('is_current', true))->count();

        return MatchResource::collection($q->paginate((int) $request->input('page.size', 25), ['*'], 'page[number]', (int) $request->input('page.number', 1)))
            ->additional(['meta' => ['counts' => [
                'open' => $counts(fn ($q) => $q->whereHas('invoice', fn ($d) => $d->where('status', DocumentStatus::MatchException->value)->whereNull('on_hold_at'))),
                'on_hold' => $counts(fn ($q) => $q->whereHas('invoice', fn ($d) => $d->whereNotNull('on_hold_at'))),
                'credit_note' => $counts(fn ($q) => $q->whereNotNull('credit_note_requested_at')->whereHas('invoice', fn ($d) => $d->where('status', DocumentStatus::MatchException->value))),
                'matched' => $counts(fn ($q) => $q->where('result', 'matched')),
                'accepted' => $counts(fn ($q) => $q->where('result', 'accepted')),
            ]]]);
    }

    /** The current match of an invoice, with every line beside its PO line and receipts. */
    public function show(Request $request, Document $document)
    {
        $match = $document->latestMatch;
        if (! $match) {
            return response()->json(['data' => null, 'meta' => ['can_accept' => MatchSettings::for($document->company_id)->canAccept($request->user())]]);
        }

        return (new MatchResource($match->loadForView()))->additional(['meta' => ['can_accept' => MatchSettings::for($document->company_id)->canAccept($request->user())]]);
    }

    public function acceptLine(Request $request, Document $document, MatchLine $line)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $match = $this->actions->acceptLine($document, $line, $request->user(), $data['reason']);

        return new MatchResource($match->loadForView());
    }

    public function acceptAll(Request $request, Document $document)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $match = $this->actions->acceptAll($document, $request->user(), $data['reason']);

        return new MatchResource($match->loadForView());
    }

    public function creditNote(Request $request, Document $document)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000', 'amount_minor' => 'nullable|integer|min:1']);
        $match = $this->actions->requestCreditNote($document, $request->user(), $data['reason'], $data['amount_minor'] ?? null);

        return new MatchResource($match->loadForView());
    }

    public function hold(Request $request, Document $document)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);

        return new DocumentResource($this->actions->hold($document, $request->user(), $data['reason'])->load('vendor', 'lines', 'latestMatch'));
    }

    public function release(Request $request, Document $document)
    {
        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        return new DocumentResource($this->actions->release($document, $request->user(), $data['note'] ?? null)->load('vendor', 'lines', 'latestMatch'));
    }

    /** Match again now (e.g. after master data was corrected). */
    public function rematch(Document $document)
    {
        if (! in_array($document->status, [DocumentStatus::Approved, DocumentStatus::MatchException], true)) {
            throw ValidationException::withMessages(['status' => 'Only approved invoices or match exceptions can be matched again.']);
        }
        MatchDocument::dispatch($document->id);

        return response()->json(['meta' => ['queued' => true]], 202);
    }
}
