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

class DashboardController extends Controller
{
    private function getRecentDonations(): Collection
    {
        return Donation::with('user:id,uuid,username', 'formula.article:id,slug,title')
            ->latest()
            ->take(10)
            ->get(['id', 'amount', 'currency', 'status', 'created_at', 'user_id', 'donor_email', 'stripe_session_id', 'paypal_order_id', 'metadata'])
            ->map(function ($donation) {
                $metadata = $donation->metadata ?? [];
                $source = $donation->paypal_order_id ? 'paypal' : 'stripe_checkout';
                $formula = $donation->formula;
                $article = $formula?->article;

                return [
                    'id' => $donation->id,
                    'source' => $source,
                    'amount' => $donation->amount,
                    'currency' => $donation->currency,
                    'status' => $donation->status,
                    'date' => $donation->created_at->format('d M, Y'),
                    'user' => $donation->user?->username ?? 'Guest',
                    'email' => $donation->donor_email ?? $donation->user?->email,
                    'stripe_session_id' => $donation->stripe_session_id,
                    'paypal_order_id' => $donation->paypal_order_id,
                    'formula_id' => $formula?->id,
                    'formula' => $formula?->formula ?? $metadata['formula'] ?? null,
                    'details' => $formula?->details ?? $metadata['details'] ?? null,
                    'article' => $article ? [
                        'slug' => $article->slug,
                        'title' => $article->title,
                    ] : null,
                    'formula_url' => $article && $formula
                        ? "/article?title={$article->slug}#formula-{$formula->uuid}"
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
                'errors' => [$e->getMessage()],
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
                            ->orWhere('stripe_session_id', 'like', "%{$search}%")
                            ->orWhere('paypal_order_id', 'like', "%{$search}%");
                    });
                })
                ->when($request->filled('status'), function ($q) use ($request) {
                    $q->where('status', $request->input('status'));
                })
                ->latest()
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Donations retrieved successfully',
                'data' => $paginator->map(function ($donation) {
                    $formula = $donation->formula;
                    $article = $formula?->article;

                    return [
                        'id' => $donation->id,
                        'source' => $donation->paypal_order_id ? 'paypal' : 'stripe_checkout',
                        'payment_id' => $donation->paypal_order_id
                            ? ($donation->metadata['payment_id'] ?? null)
                            : $donation->stripe_payment_intent_id,
                        'amount' => $donation->amount,
                        'currency' => $donation->currency,
                        'status' => $donation->status,
                        'date' => $donation->created_at->format('d M, Y'),
                        'user' => $donation->user?->username ?? 'Guest',
                        'email' => $donation->donor_email ?? $donation->user?->email,
                        'stripe_session_id' => $donation->stripe_session_id,
                        'paypal_order_id' => $donation->paypal_order_id,
                        'formula_id' => $formula?->id,
                        'formula' => $formula?->formula ?? $donation->metadata['formula'] ?? null,
                        'details' => $formula?->details ?? $donation->metadata['details'] ?? null,
                        'article' => $article ? [
                            'slug' => $article->slug,
                            'title' => $article->title,
                        ] : null,
                        'formula_url' => $article && $formula
                            ? "/article?title={$article->slug}#formula-{$formula->uuid}"
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
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }
}
