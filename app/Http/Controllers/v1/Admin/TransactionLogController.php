<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TransactionLog;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin browse/export of the append-only transaction log.
 *
 * Filters mirror the log shape so a later CSV export can reuse them:
 * event, actor_id, subject_type, date range and free-text search.
 */
class TransactionLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $query = TransactionLog::query()->with('actor:id,uuid,username');

            if ($request->filled('event')) {
                $query->where('event', $request->input('event'));
            }

            if ($request->filled('actor_id')) {
                $query->where('actor_id', (int) $request->input('actor_id'));
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

            $perPage = min(max((int) $request->input('per_page', 50), 1), 200);

            return response()->json([
                'success' => true,
                'message' => 'Transaction logs retrieved successfully',
                'data' => $query->latest('id')->paginate($perPage),
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load transaction logs.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * CSV export of the filtered view.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = TransactionLog::query()->with('actor:id,username');

        if ($request->filled('event')) {
            $query->where('event', $request->input('event'));
        }
        if ($request->filled('actor_id')) {
            $query->where('actor_id', (int) $request->input('actor_id'));
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
