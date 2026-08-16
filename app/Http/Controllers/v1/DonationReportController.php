<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DonationReportController extends Controller
{
    /**
     * Return the authenticated user's donation report summary and paginated history.
     *
     * GET /report/donations
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], Response::HTTP_UNAUTHORIZED);
            }

            $base = Donation::where('user_id', $user->id);

            // Optional date range filter (inclusive)
            $from = $request->input('from');
            $to = $request->input('to');
            $base = $base->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));

            $summary = [
                'totalDonated' => (float) (clone $base)->where('status', 'completed')->sum('amount'),
                'totalDonations' => (clone $base)->where('status', 'completed')->count(),
                'pendingDonations' => (clone $base)->where('status', 'pending')->count(),
                'failedDonations' => (clone $base)->where('status', 'failed')->count(),
                'bySource' => (clone $base)
                    ->selectRaw(
                        "CASE WHEN paypal_order_id IS NOT NULL THEN 'paypal' ELSE 'stripe' END as source, "
                        .'COUNT(*) as count, SUM(amount) as total'
                    )
                    ->where('status', 'completed')
                    ->groupBy('source')
                    ->get(),
            ];

            $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

            $paginator = $base
                ->latest()
                ->paginate($perPage);

            $donations = $paginator->map(fn (Donation $donation) => [
                'id' => $donation->id,
                'source' => $donation->paypal_order_id ? 'paypal' : 'stripe',
                'amount' => $donation->amount,
                'currency' => $donation->currency,
                'status' => $donation->status,
                'date' => $donation->created_at->format('d M, Y'),
                'payment_id' => $donation->paypal_order_id
                    ? ($donation->metadata['payment_id'] ?? $donation->paypal_order_id)
                    : ($donation->stripe_payment_intent_id ?? $donation->stripe_session_id),
                'formula' => $donation->metadata['formula'] ?? null,
                'details' => $donation->metadata['details'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Donation report retrieved successfully',
                'data' => [
                    'summary' => $summary,
                    'donations' => $donations,
                    'meta' => [
                        'currentPage' => $paginator->currentPage(),
                        'perPage' => $paginator->perPage(),
                        'total' => $paginator->total(),
                        'lastPage' => $paginator->lastPage(),
                    ],
                ],
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load donation report.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }
}
