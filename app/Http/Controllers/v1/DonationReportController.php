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

            $base = Donation::where('user_id', $user->id)
                ->with('formula.article:id,slug,title');

            // Optional date range filter (inclusive)
            $base = $base->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('to')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->input('search');
                    $q->where(function ($sq) use ($search) {
                        $sq->where('paypal_order_id', 'like', "%{$search}%")
                            ->orWhere('stripe_session_id', 'like', "%{$search}%")
                            ->orWhere('stripe_payment_intent_id', 'like', "%{$search}%")
                            ->orWhere('metadata->payment_id', 'like', "%{$search}%")
                            ->orWhere('donor_email', 'like', "%{$search}%");
                    });
                });

            $statusStats = (clone $base)
                ->selectRaw('status, COUNT(*) as count, SUM(amount) as total')
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            $completed = $statusStats['completed'] ?? null;

            $summary = [
                'totalDonated' => (float) ($completed->total ?? 0),
                'totalDonations' => (int) ($completed->count ?? 0),
                'pendingDonations' => (int) ($statusStats['pending']->count ?? 0),
                'failedDonations' => (int) ($statusStats['failed']->count ?? 0),
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

            $donations = $paginator->map(function (Donation $donation) {
                $formula = $donation->formula;
                $article = $formula?->article;

                return [
                    'id' => $donation->id,
                    'source' => $donation->paypal_order_id ? 'paypal' : 'stripe',
                    'amount' => $donation->amount,
                    'currency' => $donation->currency,
                    'status' => $donation->status,
                    'date' => $donation->created_at->format('d M, Y'),
                    'donor_email' => $donation->donor_email,
                    'payment_id' => $donation->paypal_order_id
                        ? ($donation->metadata['payment_id'] ?? $donation->paypal_order_id)
                        : ($donation->stripe_payment_intent_id ?? $donation->stripe_session_id),
                    'formula_id' => $formula?->id,
                    'formula' => $formula?->formula,
                    'details' => $formula?->details ?? $donation->metadata['details'] ?? null,
                    'article' => $article ? [
                        'slug' => $article->slug,
                        'title' => $article->title,
                    ] : null,
                    'formula_url' => $article && $formula
                        ? "/article?title={$article->slug}#formula-{$formula->uuid}"
                        : null,
                ];
            });

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
