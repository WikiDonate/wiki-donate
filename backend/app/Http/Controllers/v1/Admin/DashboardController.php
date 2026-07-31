<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Donation;
use App\Models\Payment;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * Merge Donations (stripe_checkout) and Payments (stripe_card) into a unified list.
     * Login-required only; formula/details extracted from Donation metadata.
     */
    private function getRecentDonations(): Collection
    {
        // Payments (stripe_card) — login-required
        $payments = Payment::with('user:id,uuid,username')
            ->whereNotNull('user_id')
            ->latest()
            ->take(10)
            ->get(['id', 'amount', 'currency', 'status', 'created_at', 'user_id'])
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'source' => 'stripe_card',
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                    'date' => $payment->created_at->format('d M, Y'),
                    'created_at' => $payment->created_at,
                    'user' => $payment->user?->username ?? 'Guest',
                    'email' => null,
                    'stripe_session_id' => null,
                    'formula' => null,
                    'details' => null,
                ];
            });

        // Donations (stripe_checkout) — login-required
        $donations = Donation::with('user:id,uuid,username')
            ->whereNotNull('user_id')
            ->latest()
            ->take(10)
            ->get(['id', 'amount', 'currency', 'status', 'created_at', 'user_id', 'donor_email', 'stripe_session_id', 'metadata'])
            ->map(function ($donation) {
                $metadata = $donation->metadata ?? [];

                return [
                    'id' => $donation->id,
                    'source' => 'stripe_checkout',
                    'amount' => $donation->amount,
                    'currency' => $donation->currency,
                    'status' => $donation->status,
                    'date' => $donation->created_at->format('d M, Y'),
                    'created_at' => $donation->created_at,
                    'user' => $donation->user?->username ?? 'Guest',
                    'email' => $donation->donor_email ?? $donation->user?->email,
                    'stripe_session_id' => $donation->stripe_session_id,
                    'formula' => $metadata['formula'] ?? null,
                    'details' => $metadata['details'] ?? null,
                ];
            });

        // Merge, sort by created_at descending, take 10
        return $payments->concat($donations)
            ->sortByDesc('created_at')
            ->take(10)
            ->values()
            ->map(function ($item) {
                unset($item['created_at']); // internal sort only, not exposed

                return $item;
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
                        'donations' => Payment::where('created_at', '>=', now()->subMonths(6))
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
}
