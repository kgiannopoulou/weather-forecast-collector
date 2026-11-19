<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ForecastRun;
use App\Models\Location;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Locations, providers and collection runs (operational visibility). */
class CatalogController extends Controller
{
    /** GET /api/locations */
    public function locations(): JsonResponse
    {
        return response()->json([
            'data' => Location::orderBy('id')->get()->map(fn (Location $l) => $l->toApiArray()),
        ]);
    }

    /** GET /api/providers */
    public function providers(): JsonResponse
    {
        return response()->json([
            'data' => Provider::orderBy('slug')->get(['slug', 'name']),
        ]);
    }

    /** GET /api/runs - history of collection attempts, newest first. */
    public function runs(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'location_id' => ['sometimes', 'integer'],
            'provider' => ['sometimes', 'string'],
            'status' => ['sometimes', 'in:success,failed'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $page = ForecastRun::query()
            ->select(['id', 'location_id', 'provider_id', 'run_date', 'status', 'fetched_at', 'attempts', 'error'])
            ->with('provider:id,slug')
            ->withCount(['hourly', 'daily'])
            ->when($filters['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['provider'] ?? null, fn ($q, $slug) => $q->whereRelation('provider', 'slug', $slug))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('run_date')
            ->orderBy('provider_id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        $page->getCollection()->transform(fn (ForecastRun $run) => [
            'id' => $run->id,
            'location_id' => $run->location_id,
            'provider' => $run->provider->slug,
            'run_date' => $run->run_date,
            'status' => $run->status,
            'fetched_at' => $run->fetched_at?->utc()->toIso8601ZuluString(),
            'attempts' => $run->attempts,
            'hourly_count' => $run->hourly_count,
            'daily_count' => $run->daily_count,
            'error' => $run->error,
        ]);

        return response()->json($page);
    }
}
