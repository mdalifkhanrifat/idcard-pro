<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cardholders\SyncCardholdersRequest;
use App\Services\Cardholders\CardholderApiSyncService;
use Illuminate\Http\JsonResponse;

/**
 * HR systems send their people here (docs/SYNC.md).
 */
class CardholderSyncController extends Controller
{
    public function __construct(private CardholderApiSyncService $sync) {}

    /**
     * Create or update each record by employee number; 200 with a result for every record,
     * even when some failed.
     */
    public function store(SyncCardholdersRequest $request): JsonResponse
    {
        /** @var array<int, array<array-key, mixed>> $records */
        $records = $request->validated('records');

        return response()->json(['data' => $this->sync->run(
            $records,
            $request->boolean('dry_run'),
            $request->boolean('create_missing_lookups'),
            $request->user(),
        )]);
    }
}
