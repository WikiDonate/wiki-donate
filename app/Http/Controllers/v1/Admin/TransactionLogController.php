<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TransactionLog;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin browse/export of the append-only transaction log.
 *
 * Filters mirror the log shape so the CSV export reuses them: event,
 * category, actor_id, subject_type, date range and free-text search.
 */
class TransactionLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $paginator = $this->filtered($request)
                ->with('actor:id,uuid,username')
                ->latest('id')
                ->paginate($this->perPage($request));

            return response()->json([
                'success' => true,
                'message' => 'Transaction logs retrieved successfully',
                'data' => $paginator->items(),
                'meta' => [
                    'currentPage' => $paginator->currentPage(),
                    'lastPage' => $paginator->lastPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load transaction logs.',
                'errors' => [$this->apiErrorMessage($e)],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * Event/category options for the admin filters, derived from the log so a
     * newly recorded event type shows up without a frontend change.
     */
    public function filters(): JsonResponse
    {
        try {
            $events = TransactionLog::query()
                ->distinct()
                ->orderBy('event')
                ->pluck('event')
                ->all();

            $categories = collect($events)
                ->map(fn (string $event) => explode('.', $event)[0])
                ->unique()
                ->sort()
                ->values()
                ->all();

            return response()->json([
                'success' => true,
                'message' => 'Transaction log filters retrieved successfully',
                'data' => [
                    'events' => $events,
                    'categories' => $categories,
                ],
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load transaction log filters.',
                'errors' => [$this->apiErrorMessage($e)],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    private function filtered(Request $request): Builder
    {
        $query = TransactionLog::query();

        if ($request->filled('event')) {
            $query->where('event', $request->input('event'));
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_id', (int) $request->input('actor_id'));
        }

        // Category convenience filter (events are namespaced
        // `<category>.<action>`, e.g. organization.verified).
        if ($request->filled('category')) {
            $query->where('event', 'like', $request->input('category').'.%');
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->input('subject_type'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('message', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 15), 1), 200);
    }

    /**
     * CSV export of the filtered view (same filters as the list endpoint).
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->with('actor:id,username');

        $callback = function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'id', 'uuid', 'event', 'actor', 'actor_role', 'subject_type', 'subject_id',
                'message', 'before', 'after', 'meta', 'created_at',
            ]);

            $query->latest('id')->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->id,
                        $row->uuid,
                        $row->event,
                        $row->actor?->username ?? '',
                        $row->actor_role ?? '',
                        $row->subject_type ?? '',
                        $row->subject_id ?? '',
                        $row->message ?? '',
                        $row->before !== null ? json_encode($row->before) : '',
                        $row->after !== null ? json_encode($row->after) : '',
                        $row->meta !== null ? json_encode($row->meta) : '',
                        $row->created_at?->toDateTimeString() ?? '',
                    ]);
                }
            });

            fclose($handle);
        };

        return response()->streamDownload($callback, 'transaction-log-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
