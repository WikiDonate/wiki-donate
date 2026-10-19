<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrganizationSearchController extends Controller
{
    /**
     * Registry-only organization autocomplete for donor/editor formula rows.
     *
     * Source of truth is the admin-curated `organizations` table — no external
     * charity API is ever called on donor paths (that lookup is admin-only and
     * used by the admin "Add organization" modal). Free-text (custom) entries
     * remain allowed client-side; this endpoint simply never returns them.
     *
     * Rows are matched by name, normalized_name or EIN. Admin-created registry
     * orgs are donor-visible immediately, verified or not — the client renders
     * the three-state badge from `payout_status`/`is_verified`.
     */
    public function search(Request $request)
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            return response()->json([
                'success' => true,
                'message' => 'Query too short',
                'data' => [],
            ], Response::HTTP_OK);
        }

        $normalized = Organization::normalizeName($query);

        $rows = Organization::query()
            ->where(function ($q) use ($query, $normalized) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('ein', 'like', "%{$query}%")
                    ->orWhere('normalized_name', 'like', "%{$normalized}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(fn (Organization $org) => [
                'organization_id' => $org->id,
                'name' => $org->name,
                'ein' => $org->ein,
                'city' => $org->city,
                'state' => $org->state,
                'website' => $org->website,
                'rating' => null,
                'payout_status' => $org->payout_status ?: 'unverified',
                'is_verified' => $org->isVerified(),
                'source' => 'local',
            ])
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Organizations retrieved successfully',
            'data' => $rows,
        ], Response::HTTP_OK);
    }
}
