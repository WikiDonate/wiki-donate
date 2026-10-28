<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Donation;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private function getRecentDonations(): Collection
    {
        return Donation::with('user:id,uuid,username', 'formula.article:id,slug,title')
            ->latest()
            ->take(10)
            ->get(['id', 'amount', 'currency', 'status', 'created_at', 'user_id', 'donor_email', 'payment_method', 'gateway_reference', 'metadata'])
            ->map(function ($donation) {
                $metadata = $donation->metadata ?? [];
                $formula = $donation->formula;
                $article = $formula?->article;
                $snapshot = $metadata['formula_snapshot'] ?? null;
                $usesSnapshot = is_array($snapshot) && count($snapshot) > 0;

                // Prefer the frozen snapshot's article link (see DonationReportController).
                $snapshotUuid = $metadata['formula_uuid'] ?? null;

                return [
                    'id' => $donation->id,
                    'payment_method' => $donation->paymentMethodCode() ?? 'unknown',
                    'amount' => $donation->amount,
                    'currency' => $donation->currency,
                    'status' => $donation->status,
                    'date' => $donation->created_at->format('d M, Y'),
                    'date_raw' => $donation->created_at->toISOString(),
                    'user' => $donation->user?->username ?? 'Guest',
                    'email' => $donation->donor_email ?? $donation->user?->email,
                    'gateway_reference' => $donation->gateway_reference,
                    'formula_id' => $metadata['formula_id'] ?? $formula?->id,
                    'formula' => $snapshot ?? $metadata['formula'] ?? $formula?->formula,
                    'formula_snapshot_used' => $usesSnapshot,
                    'details' => $metadata['details'] ?? $formula?->details ?? null,
                    'article' => $article ? [
                        'slug' => $article->slug,
                        'title' => $article->title,
                    ] : null,
                    'formula_url' => $article && ($snapshotUuid ?? $formula?->uuid)
                        ? "/article?title={$article->slug}#formula-".($snapshotUuid ?? $formula->uuid)
                        : null,
                ];
            });
    }

    public function index(): JsonResponse
    {
        try {
            $stats = Cache::store('file')->remember('dashboard', 3600, function () {
                return [
                    'totalUsers' => User::count(),
                    'totalArticles' => Article::count(),

                    'recentDonations' => $this->getRecentDonations(),

                    'recentUsers' => User::latest()
                        ->take(10)
                        ->get(['uuid', 'username', 'email', 'created_at'])
                        ->map(function ($user) {
                            return [
                                'uuid' => $user->uuid,
                                'username' => $user->username,
                                'email' => $user->email,
                                'joinedAt' => $user->created_at->format('d M, Y'),
                            ];
                        }),

                    'monthlyStats' => [
                        'donations' => Donation::where('created_at', '>=', now()->subMonths(6))
                            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count, SUM(amount) as total")
                            ->groupBy('month')
                            ->orderBy('month')
                            ->get(),

                        'registrations' => User::where('created_at', '>=', now()->subMonths(6))
                            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total")
                            ->groupBy('month')
                            ->orderBy('month')
                            ->get(),
                    ],

                    // Completed donations split by the generic method code.
                    // Data-driven: a new gateway shows up here with no code
                    // change. Unclassified rows are "unknown", never inferred
                    // to be Stripe.
                    'donationsByMethod' => Donation::where('status', 'completed')
                        ->selectRaw(
                            "COALESCE(payment_method, 'unknown') as payment_method, "
                            .'COUNT(*) as count, SUM(amount) as total'
                        )
                        ->groupBy(DB::raw("COALESCE(payment_method, 'unknown')"))
                        ->orderByDesc('total')
                        ->get(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Dashboard data retrieved successfully',
                'data' => $stats,
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard data.',
                'errors' => [$this->apiErrorMessage($e)],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    public function donations(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 15);
            $perPage = min(max($perPage, 1), 100);

            $paginator = Donation::with('user:id,uuid,username', 'formula.article:id,slug,title')
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->input('search');
                    $q->where(function ($sq) use ($search) {
                        $sq->whereHas('user', fn ($uq) => $uq->where('username', 'like', "%{$search}%"))
                            ->orWhere('donor_email', 'like', "%{$search}%")
                            ->orWhere('gateway_reference', 'like', "%{$search}%")
                            ->orWhere('payment_method', 'like', "%{$search}%")
                            ->orWhere('metadata->payment_id', 'like', "%{$search}%");
                    });
                })
                ->when($request->filled('status'), function ($q) use ($request) {
                    $q->where('status', $request->input('status'));
                })
                ->when($request->filled('method'), function ($q) use ($request) {
                    $q->where('payment_method', $request->input('method'));
                })
                ->latest()
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Donations retrieved successfully',
                'data' => $paginator->map(function ($donation) {
                    $formula = $donation->formula;
                    $article = $formula?->article;
                    $snapshotUuid = $donation->metadata['formula_uuid'] ?? null;

                    return [
                        'id' => $donation->id,
                        'payment_method' => $donation->paymentMethodCode() ?? 'unknown',
                        'payment_id' => $donation->metadata['payment_id'] ?? $donation->gateway_reference,
                        'amount' => $donation->amount,
                        'currency' => $donation->currency,
                        'status' => $donation->status,
                        'date' => $donation->created_at->format('d M, Y'),
                        'date_raw' => $donation->created_at->toISOString(),
                        'user' => $donation->user?->username ?? 'Guest',
                        'email' => $donation->donor_email ?? $donation->user?->email,
                        'gateway_reference' => $donation->gateway_reference,
                        'formula_id' => $donation->metadata['formula_id'] ?? $formula?->id,
                        'formula' => $donation->metadata['formula_snapshot']
                            ?? $donation->metadata['formula']
                            ?? $formula?->formula,
                        'formula_snapshot_used' => is_array($donation->metadata['formula_snapshot'] ?? null)
                            && count($donation->metadata['formula_snapshot']) > 0,
                        'details' => $donation->metadata['details'] ?? $formula?->details ?? null,
                        'article' => $article ? [
                            'slug' => $article->slug,
                            'title' => $article->title,
                        ] : null,
                        'formula_url' => $article && ($snapshotUuid ?? $formula?->uuid)
                            ? "/article?title={$article->slug}#formula-".($snapshotUuid ?? $formula->uuid)
                            : null,
                    ];
                }),
                'meta' => [
                    'currentPage' => $paginator->currentPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'lastPage' => $paginator->lastPage(),
                ],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load donations.',
                'errors' => [$this->apiErrorMessage($e)],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }
}
