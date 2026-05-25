<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Cause;
use App\Models\Ngo;
use App\Models\Payment;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $stats = Cache::store('file')->remember('admin_dashboard_data', 3600, function () {
                return [
                    'totalUsers' => User::count(),
                    'totalArticles' => Article::count(),
                    'totalCauses' => Cause::count(),
                    'totalNgos' => Ngo::count(),

                    'recentDonations' => Payment::with('user:id,uuid,username')
                        ->latest()
                        ->take(10)
                        ->get(['id', 'amount', 'currency', 'status', 'created_at', 'user_id'])
                        ->map(function ($donation) {
                            return [
                                'amount' => $donation->amount,
                                'currency' => $donation->currency,
                                'status' => $donation->status,
                                'date' => $donation->created_at->format('d M, Y'),
                                'user' => $donation->user?->username ?? 'Guest',
                            ];
                        }),

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
