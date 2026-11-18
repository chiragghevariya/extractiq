<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\EventResource;
use App\Http\Resources\MatchResource;
use App\Http\Resources\PageResource;
use App\Jobs\MatchDocument;
use App\Jobs\ValidateDocument;
use App\Models\Document;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Matching\VendorResolver;
use App\Services\Pipeline\EventRecorder;
use App\Services\Pipeline\DocumentIngestor;
use App\Services\Pipeline\DocumentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentWorkflow $workflow) {}

    public function index(Request $request)
    {
        $request->validate([
            'filter.status' => 'nullable|string',
            'filter.type' => ['nullable', Rule::enum(DocumentType::class)],
            'filter.source' => 'nullable|in:upload,email,api',
            'filter.uploaded_by' => 'nullable|integer',
            'filter.q' => 'nullable|string|max:100',
            'filter.vendor_id' => 'nullable|integer',
            'sort' => 'nullable|in:received_at,-received_at,total_minor,-total_minor,document_date,-document_date,confidence,-confidence',
            'page.size' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Document::query()->with('vendor', 'uploader:id,name', 'assignee:id,name');
        if ($status = $request->input('filter.status')) {
            $query->whereIn('status', array_filter(explode(',', $status), fn ($s) => DocumentStatus::tryFrom($s) !== null));
        }
        foreach (['type', 'source', 'vendor_id', 'uploaded_by'] as $f) {
            if ($v = $request->input("filter.$f")) {
                $query->where($f, $v);
            }
        }
        if ($q = $request->input('filter.q')) {
            $like = '%'.mb_strtolower($q).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(original_filename) like ?', [$like])
                ->orWhereRaw('lower(document_number) like ?', [$like])
                ->orWhereRaw('lower(po_number) like ?', [$like])
                ->orWhereRaw('lower(vendor_name) like ?', [$like])
                ->orWhereRaw('lower(email_from) like ?', [$like]));
        }
        $sort = $request->input('sort', '-received_at');
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderByDesc('id');

        return DocumentResource::collection(
            $query->paginate((int) $request->input('page.size', 25), ['*'], 'page[number]', (int) $request->input('page.number', 1))->withQueryString()
        );
    }

    public function store(Request $request, DocumentIngestor $ingestor)
    {
        $max = config('extractiq.max_upload_kb');
        $mimes = implode(',', config('extractiq.allowed_mimes'));
        $request->validate([
            'files' => 'required|array|min:1|max:20',
            'files.*' => "file|mimes:$mimes|max:$max",
            'type' => ['nullable', Rule::enum(DocumentType::class)],
        ]);
        // Browser (Sanctum session) uploads are "upload"; personal-access-token clients are "api".
        $source = $request->user()->currentAccessToken() instanceof PersonalAccessToken ? 'api' : 'upload';

        $results = [];
        foreach ($request->file('files') as $file) {
            [$doc, $created] = $ingestor->ingest(
                $request->user()->company,
                $file->get(),
                $file->getClientOriginalName(),
                $file->getMimeType() ?? 'application/octet-stream',
                ['source' => $source, 'user_id' => $request->user()->id, 'declared_type' => $request->input('type')],
            );
            $results[] = ['doc' => $doc->fresh() ?? $doc, 'created' => $created];
        }

        return DocumentResource::collection(collect($results)->pluck('doc'))
            ->additional(['meta' => [
                'created' => collect($results)->where('created', true)->count(),
                'duplicates' => collect($results)->where('created', false)->map(fn ($r) => (string) $r['doc']->id)->values(),
            ]])
            ->response()->setStatusCode(201);
    }

    public function show(Document $document)
    {
        return new DocumentResource($document->load('vendor', 'uploader:id,name', 'assignee:id,name', 'lines', 'latestMatch.purchaseOrder.vendor', 'latestMatch.lines'));
    }

    /** PATCH /documents/{id} — kept as an alias of /extraction for older clients. */
    public function update(Request $request, Document $document)
    {
        return $this->extraction($request, $document);
    }

    /**
     * PATCH /documents/{id}/extraction — reviewer corrections. Body: any editable field, optional
     * `lines` (replaces all), optional `accepted` (form keys confirmed as-is, e.g. ["document_date", "lines.2"]).
     * Writes a field-level diff to document_events and feeds vendor memory.
     */
    public function extraction(Request $request, Document $document)
    {
        $data = $request->validate($this->correctionRules());
        [$fields, $lines, $accepted] = $this->splitCorrection($data);

        $doc = $this->workflow->updateExtraction($document, $fields, $lines, $accepted, $request->user());

        return new DocumentResource($doc->load('vendor', 'assignee:id,name', 'lines', 'latestMatch'));
    }

    private function correctionRules(): array
    {
        return [
            'type' => ['sometimes', Rule::enum(DocumentType::class)],
            'vendor_name' => 'sometimes|nullable|string|max:255',
            'vendor_id' => 'sometimes|nullable|integer|exists:vendors,id',
            'document_number' => 'sometimes|nullable|string|max:255',
            'po_number' => 'sometimes|nullable|string|max:255',
            'document_date' => 'sometimes|nullable|date_format:Y-m-d',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'payment_terms' => 'sometimes|nullable|string|max:255',
            'currency' => 'sometimes|nullable|string|size:3|alpha',
            'subtotal_minor' => 'sometimes|nullable|integer',
            'tax_minor' => 'sometimes|nullable|integer',
            'shipping_minor' => 'sometimes|nullable|integer',
            'total_minor' => 'sometimes|nullable|integer',
            'lines' => 'sometimes|array|max:500',
            'lines.*.sku' => 'nullable|string|max:255',
            'lines.*.description' => 'required_with:lines|string|max:255',
            'lines.*.quantity' => 'required_with:lines|numeric|min:0',
            'lines.*.uom' => 'nullable|string|max:20',
            'lines.*.unit_price_minor' => 'nullable|integer',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.amount_minor' => 'nullable|integer',
            'accepted' => 'sometimes|array|max:100',
            'accepted.*' => 'string|max:40',
        ];
    }

    /** @return array{0: array, 1: ?array, 2: list<string>} */
    private function splitCorrection(array $data): array
    {
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        $lines = $data['lines'] ?? null;
        $accepted = array_values($data['accepted'] ?? []);
        unset($data['lines'], $data['accepted']);

        return [$data, $lines, $accepted];
    }

    public function destroy(Document $document)
    {
        $document->delete();

        return response()->noContent();
    }

    public function file(Document $document)
    {
        return Storage::disk($document->storage_disk)->response($document->storage_path, $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($document->original_filename).'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** POST /documents/{id}/approve — optional pending corrections (same body as /extraction) are applied first. */
    public function approve(Request $request, Document $document)
    {
        $data = $request->validate($this->correctionRules());
        [$fields, $lines, $accepted] = $this->splitCorrection($data);
        $pending = ($fields || $lines !== null || $accepted) ? ['fields' => $fields, 'lines' => $lines, 'accepted' => $accepted] : null;

        $doc = $this->workflow->approve($document, $request->user(), $pending);

        return new DocumentResource($doc->fresh()->load('vendor', 'assignee:id,name', 'lines', 'latestMatch'));
    }

    public function reject(Request $request, Document $document)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        $this->workflow->reject($document, $request->user(), $data['reason']);

        return new DocumentResource($document->fresh()->load('vendor', 'assignee:id,name', 'lines', 'latestMatch'));
    }

    public function reopen(Request $request, Document $document)
    {
        $this->workflow->reopen($document, $request->user());

        return new DocumentResource($document->fresh()->load('vendor', 'assignee:id,name', 'lines', 'latestMatch'));
    }

    public function reassign(Request $request, Document $document)
    {
        $data = $request->validate(['assignee_id' => ['present', 'nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $request->user()->company_id)]]);
        $assignee = $data['assignee_id'] ? User::find($data['assignee_id']) : null;
        $this->workflow->reassign($document, $request->user(), $assignee);

        return new DocumentResource($document->fresh()->load('vendor', 'assignee:id,name', 'lines', 'latestMatch'));
    }

    public function retry(Request $request, Document $document)
    {
        $step = $this->workflow->retry($document, $request->user());

        return response()->json(['meta' => ['queued_step' => $step]], 202);
    }

    public function overrideMatch(Request $request, Document $document)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $this->workflow->overrideMatch($document, $request->user(), $data['reason']);

        return new DocumentResource($document->fresh()->load('vendor', 'lines', 'latestMatch'));
    }

    public function events(Document $document)
    {
        return EventResource::collection($document->events()->with('user')->get());
    }

    public function pages(Document $document)
    {
        return PageResource::collection($document->pages()->get());
    }

    /** Normalised OCR for one page (words/lines/key_values/tables); ?include=raw adds the provider payload. */
    public function pageOcr(Request $request, Document $document, int $page)
    {
        $p = $document->pages()->where('page_number', $page)->firstOrFail();
        $ocr = $p->ocr ?? ['words' => [], 'lines' => [], 'key_values' => [], 'tables' => []];

        return response()->json(['data' => [
            'type' => 'document-page-ocr',
            'id' => "{$document->id}-{$page}",
            'attributes' => [
                'page_number' => $p->page_number,
                'width' => $p->width,
                'height' => $p->height,
                'provider' => $p->ocr_provider,
                'confidence' => $p->confidence,
            ] + $ocr + ($request->input('include') === 'raw' ? ['raw' => $p->ocr_raw] : []),
        ]]);
    }

    public function pageImage(Document $document, int $page)
    {
        $p = $document->pages()->where('page_number', $page)->whereNotNull('image_path')->firstOrFail();

        return Storage::disk($p->image_disk ?? $document->storage_disk)->response($p->image_path, "page-$page", [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function matches(Document $document)
    {
        return MatchResource::collection($document->matches()->with('purchaseOrder.vendor', 'lines')->get());
    }

    /**
     * Pick the vendor by hand (or clear it). With remember_alias, the name as printed on the
     * document becomes an alias of that vendor so the next one resolves on its own.
     */
    public function vendor(Request $request, Document $document, VendorResolver $resolver)
    {
        $data = $request->validate([
            'vendor_id' => ['present', 'nullable', 'integer', Rule::exists('vendors', 'id')->where('company_id', $request->user()->company_id)],
            'remember_alias' => 'boolean',
        ]);
        $before = $document->vendor_id;
        $vendor = $data['vendor_id'] ? Vendor::find($data['vendor_id']) : null;
        $document->vendor_id = $vendor?->id;
        $document->vendor_match = $vendor
            ? ['method' => 'manual', 'confidence' => 1.0, 'candidates' => $document->vendor_match['candidates'] ?? [], 'extracted_name' => $document->vendor_name]
            : null;
        if (! $vendor) {
            $resolver->apply($document);
        }
        $document->save();
        $aliased = $vendor && ($data['remember_alias'] ?? false) && $document->vendor_name && $vendor->addAlias($document->vendor_name);
        app(EventRecorder::class)->record($document, 'vendor', 'succeeded', meta: [
            'diff' => ['fields' => ['vendor_id' => ['before' => $before, 'after' => $document->vendor_id]]],
            'vendor' => $vendor?->name, 'alias_added' => $aliased ? $document->vendor_name : null,
        ], userId: $request->user()->id);

        if (in_array($document->status, [DocumentStatus::Extracted, DocumentStatus::NeedsReview, DocumentStatus::Validated], true)) {
            ValidateDocument::dispatchSync($document->id);
        } elseif ($document->status === DocumentStatus::MatchException) {
            MatchDocument::dispatch($document->id);
        }

        return new DocumentResource($document->fresh()->load('vendor', 'assignee:id,name', 'lines', 'latestMatch'));
    }
}
