<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CardholderStatus;
use App\Enums\CardholderSyncKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cardholders\DeactivateCardholdersRequest;
use App\Http\Requests\Cardholders\ListCardholderSyncsRequest;
use App\Http\Requests\Cardholders\SaveSyncSettingsRequest;
use App\Http\Requests\Cardholders\SyncCardholdersRequest;
use App\Http\Resources\CardholderSyncResource;
use App\Services\Cardholders\CardholderApiSyncService;
use App\Services\Cardholders\CardholderSyncLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * HR systems send their people here (docs/SYNC.md).
 */
class CardholderSyncController extends Controller
{
    public function __construct(private CardholderApiSyncService $sync, private CardholderSyncLogService $log) {}

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

    /**
     * The run log, newest first: every run for whoever manages integrations, its own for an HR system's account.
     */
    public function runs(ListCardholderSyncsRequest $request): AnonymousResourceCollection
    {
        return CardholderSyncResource::collection($this->log->list($request->user(), $request->validated()))
            ->additional(['meta' => ['kinds' => array_map(fn (CardholderSyncKind $kind): string => $kind->value, CardholderSyncKind::cases())]]);
    }

    /**
     * What a record may hold, named in the reader's language, and the organization's HR names for the fields (for
     * the page that keeps them, and for the HR system's developers).
     */
    public function fields(): JsonResponse
    {
        abort_unless(Gate::any(['integrations.manage', 'cardholders.sync', 'settings.update']), 403);

        ['fields' => $fields, 'sync_fields' => $names] = $this->log->fields();

        return response()->json(['data' => $fields, 'meta' => ['sync_fields' => (object) $names]]);
    }

    /**
     * The HR sync's settings (HR field names, deleting old runs), with what may be chosen and whether this person
     * may change them.
     */
    public function settings(Request $request): JsonResponse
    {
        abort_unless(Gate::any(['integrations.manage', 'settings.update']), 403);

        return $this->settingsResponse($this->log->settings(), $request);
    }

    /**
     * Change some of the HR sync's settings; what is left out stays.
     */
    public function saveSettings(SaveSyncSettingsRequest $request): JsonResponse
    {
        return $this->settingsResponse($this->log->saveSettings($request->validated()), $request);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function settingsResponse(array $settings, Request $request): JsonResponse
    {
        return response()->json([
            'data' => [...$settings, 'sync_fields' => (object) $settings['sync_fields']],
            'meta' => ['log_days' => config('cardholder_sync.log_days'), 'can_update' => $request->user()->can('settings.update')],
        ]);
    }

    /**
     * Mark the people who left as no longer active, by employee number; 200 with a result for every number,
     * even when some are not found. Nobody is deleted.
     */
    public function deactivate(DeactivateCardholdersRequest $request): JsonResponse
    {
        /** @var array<int, string> $employeeNos */
        $employeeNos = $request->validated('employee_nos');

        return response()->json(['data' => $this->sync->deactivate(
            $employeeNos,
            $request->filled('status') ? CardholderStatus::from($request->string('status')->value()) : null,
            $request->boolean('dry_run'),
            $request->user(),
        )]);
    }
}
