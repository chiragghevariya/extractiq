<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Review queue: documents waiting for a person, most urgent first (due date, then arrival).
 * Includes counts, the SLA config and the ordered ids so the UI can step through with J/K.
 */
class ReviewQueueController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'filter.assignee' => 'nullable|string|max:20',        // me | unassigned | {user id}
            'filter.sla' => 'nullable|in:overdue,due_soon',
            'filter.status' => 'nullable|in:needs_review,validated',
            'filter.q' => 'nullable|string|max:100',
        ]);
        $user = $request->user();
        $slaHours = (int) config('extractiq.review.sla_hours');
        $warnHours = (int) config('extractiq.review.sla_warning_hours');
        $status = $request->input('filter.status', DocumentStatus::NeedsReview->value);

        $base = fn () => Document::query()->where('status', $status);
        $query = $base()->with('vendor', 'assignee:id,name', 'uploader:id,name');

        match ($assignee = $request->input('filter.assignee')) {
            null, '' => null,
            'me' => $query->where('assignee_id', $user->id),
            'unassigned' => $query->whereNull('assignee_id'),
            default => $query->where('assignee_id', (int) $assignee),
        };
        if ($sla = $request->input('filter.sla')) {
            $this->slaScope($query, $sla, $slaHours, $warnHours);
        }
        if ($q = $request->input('filter.q')) {
            $like = '%'.mb_strtolower($q).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(document_number) like ?', [$like])->orWhereRaw('lower(vendor_name) like ?', [$like])->orWhereRaw('lower(original_filename) like ?', [$like]));
        }
        // Most urgent first: due date (documents without one last), then oldest arrival.
        $query->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('received_at')->orderBy('id');

        $page = $query->paginate((int) $request->input('page.size', 50), ['*'], 'page[number]', (int) $request->input('page.number', 1));

        return DocumentResource::collection($page)->additional(['meta' => [
            'counts' => [
                'total' => $base()->count(),
                'mine' => $base()->where('assignee_id', $user->id)->count(),
                'unassigned' => $base()->whereNull('assignee_id')->count(),
                'overdue' => $this->slaScope($base(), 'overdue', $slaHours, $warnHours)->count(),
                'due_soon' => $this->slaScope($base(), 'due_soon', $slaHours, $warnHours)->count(),
            ],
            'sla' => ['hours' => $slaHours, 'warning_hours' => $warnHours],
            // Same ordering, all pages: lets the detail view step through the queue with J/K.
            'order' => $base()->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('received_at')->orderBy('id')->limit(500)->pluck('id')->map(fn ($id) => (string) $id),
        ]]);
    }

    private function slaScope(Builder $q, string $which, int $slaHours, int $warnHours): Builder
    {
        $overdueBefore = now()->subHours($slaHours);

        return $which === 'overdue'
            ? $q->where('received_at', '<', $overdueBefore)
            : $q->whereBetween('received_at', [$overdueBefore, now()->subHours(max(0, $slaHours - $warnHours))]);
    }
}
