<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Document;
use App\Models\DocumentEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $days = 14;
        $since = now()->startOfDay()->subDays($days - 1);

        $byStatus = Document::query()->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');
        $statusCounts = collect(DocumentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]);

        $volume = Document::query()->where('received_at', '>=', $since)
            ->select(DB::raw('date(received_at) as d'), 'source', DB::raw('count(*) as c'))
            ->groupBy('d', 'source')->get();
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i)->toDateString();
            $series[] = [
                'date' => $date,
                'upload' => (int) $volume->where('d', $date)->where('source', 'upload')->sum('c'),
                'email' => (int) $volume->where('d', $date)->where('source', 'email')->sum('c'),
            ];
        }

        // Straight-through = pushed without any human review event.
        $pushed = Document::query()->where('status', DocumentStatus::Pushed->value);
        $pushedCount = (clone $pushed)->count();
        $touchless = (clone $pushed)->whereDoesntHave('events', fn ($q) => $q->whereIn('step', ['review', 'match_override']))->count();

        // Median-ish processing time: received -> validated/needs_review, from the event log.
        $avgPipelineMs = (int) DocumentEvent::query()
            ->whereIn('step', ['ocr', 'extract', 'validate'])->where('status', 'succeeded')
            ->where('created_at', '>=', $since)
            ->avg('duration_ms');

        $pushedValue = Document::query()->where('status', DocumentStatus::Pushed->value)
            ->select('currency', DB::raw('sum(total_minor) as total'))->groupBy('currency')->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'total_minor' => (int) $r->getAttribute('total')])->values();

        return response()->json([
            'data' => [
                'type' => 'dashboard-stats',
                'id' => 'current',
                'attributes' => [
                    'total_documents' => $statusCounts->sum(),
                    'status_counts' => $statusCounts,
                    'needs_attention' => $statusCounts['needs_review'] + $statusCounts['match_exception'] + $statusCounts['push_failed'] + $statusCounts['failed'],
                    'in_pipeline' => $statusCounts['received'] + $statusCounts['ocr_done'] + $statusCounts['extracted'],
                    'straight_through_rate' => $pushedCount ? round($touchless / $pushedCount, 3) : null,
                    'avg_step_ms' => $avgPipelineMs,
                    'pushed_value' => $pushedValue,
                    'volume' => $series,
                    'failed_steps_24h' => DocumentEvent::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
                ],
            ],
        ]);
    }

    public function activity(Request $request)
    {
        $limit = min(100, (int) $request->input('page.size', 20));

        return EventResource::collection(
            DocumentEvent::query()->with('document:id,original_filename', 'user:id,name')
                ->whereIn('status', ['succeeded', 'failed'])
                ->latest('created_at')->latest('id')->limit($limit)->get()
        );
    }
}
