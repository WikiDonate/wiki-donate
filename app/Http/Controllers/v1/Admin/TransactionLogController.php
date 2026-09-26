<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TransactionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TransactionLogController extends Controller
{
    /**
     * Report-friendly paginated audit log.
     *
     * Filters: event, category, actor_id, subject_type, subject_id, from, to, q
     */
    public function index(Request $request): JsonResponse
    {
        $query = TransactionLog::query()
            ->with('actor:id,username')
            ->orderByDesc('created_at');

        if ($request->filled('event')) {
            $query->where('event', $request->input('event'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->input('actor_id'));
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->input('subject_type'));
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->input('subject_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($builder) use ($q) {
                $builder->where('event', 'like', "%{$q}%")
                    ->orWhere('note', 'like', "%{$q}%");
            });
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return response()->json([
            'success' => true,
            'message' => 'Transaction logs retrieved successfully',
            'data' => $query->paginate($perPage)->through(fn ($log) => $this->transform($log)),
        ], Response::HTTP_OK);
    }

    private function transform(TransactionLog $log): array
    {
        return [
            'id' => $log->id,
            'uuid' => $log->uuid,
            'event' => $log->event,
            'category' => $log->category,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'actor' => $log->actor?->username,
            'actor_id' => $log->actor_id,
            'before' => $log->before,
            'after' => $log->after,
            'note' => $log->note,
            'created_at' => $log->created_at?->toDateTimeString(),
            'ip_address' => $log->ip_address,
        ];
    }
}
