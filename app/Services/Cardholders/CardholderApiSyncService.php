<?php

namespace App\Services\Cardholders;

use App\Enums\CardholderStatus;
use App\Enums\CardholderSyncKind;
use App\Models\User;
use App\Repositories\Contracts\CardholderSyncRepositoryInterface;
use App\Services\Webhooks\WebhookNotifier;
use Closure;

/**
 * An HR system's batch of people. POST /api/v1/sync/cardholders: each record is found by employee number
 * and created or updated on its own, by the same rules as everywhere else (CardholderSyncService).
 * POST /api/v1/sync/cardholders/deactivate: the people who left, by employee number, marked as no longer
 * active (never deleted). One record failing never holds back the others. A dry run says what would happen
 * and writes nothing. A run is kept (cardholder_syncs: who, when, what kind, how many, never what was sent)
 * and, for the webhooks that chose cardholder.synced, told of as one delivery.
 */
class CardholderApiSyncService
{
    public const RESULTS = ['created', 'updated', 'unchanged', 'failed'];

    public const DEACTIVATE_RESULTS = ['deactivated', 'unchanged', 'not_found', 'failed'];

    /** Says in one delivery what a request did, for the webhooks that chose it (config webhooks.batches). */
    private const SYNCED_EVENT = 'cardholder.synced';

    /** Where each kind's results are counted in cardholder_syncs; a deactivation is an update of the person. */
    private const COUNT_COLUMNS = [
        'upsert' => ['created' => 'created_count', 'updated' => 'updated_count', 'unchanged' => 'unchanged_count', 'failed' => 'failed_count'],
        'deactivate' => ['deactivated' => 'updated_count', 'unchanged' => 'unchanged_count', 'not_found' => 'not_found_count', 'failed' => 'failed_count'],
    ];

    public function __construct(
        private CardholderSyncService $sync,
        private CardholderSyncMapper $mapper,
        private CardholderSyncRepositoryInterface $syncs,
        private WebhookNotifier $webhooks,
    ) {}

    /**
     * @param  array<int, array<array-key, mixed>>  $records
     * @return array{id: string|null, dry_run: bool, summary: array<string, int>, records: array<int, array{index: int, employee_no: mixed, result: string, id: string|null, errors: object}>, lookups_created: array<int, string>}
     */
    public function run(array $records, bool $dryRun, bool $createMissingLookups, ?User $actor): array
    {
        if ($dryRun) {
            return ['id' => null, ...$this->walk($records, true, $createMissingLookups, $actor)];
        }

        /** @var array{id: string|null, dry_run: bool, summary: array<string, int>, records: array<int, array{index: int, employee_no: mixed, result: string, id: string|null, errors: object}>, lookups_created: array<int, string>} */
        return $this->logged(CardholderSyncKind::Upsert, count($records), $actor, function (array &$changed) use ($records, $createMissingLookups, $actor): array {
            return $this->walk($records, false, $createMissingLookups, $actor, $changed);
        });
    }

    /**
     * Mark the people who left as no longer active, one result per employee number.
     *
     * @param  array<int, string>  $employeeNos
     * @param  CardholderStatus|null  $status  inactive or retired; null: inactive, leaving anyone already not active as they are
     * @return array{id: string|null, dry_run: bool, summary: array<string, int>, records: array<int, array{index: int, employee_no: string, result: string, id: string|null, status: string|null, errors: object}>}
     */
    public function deactivate(array $employeeNos, ?CardholderStatus $status, bool $dryRun, ?User $actor): array
    {
        if ($dryRun) {
            return ['id' => null, ...$this->walkDeactivate($employeeNos, $status, true)];
        }

        /** @var array{id: string|null, dry_run: bool, summary: array<string, int>, records: array<int, array{index: int, employee_no: string, result: string, id: string|null, status: string|null, errors: object}>} */
        return $this->logged(CardholderSyncKind::Deactivate, count($employeeNos), $actor, function (array &$changed) use ($employeeNos, $status): array {
            return $this->walkDeactivate($employeeNos, $status, false, $changed);
        });
    }

    /**
     * Keep a run from the start, so a run that breaks off is known (no finished_at) and told of; webhooks hear
     * of the whole run once (cardholder.synced), or of each person, as each chose.
     *
     * @param  Closure(array{created: array<int, string>, updated: array<int, string>}&): array{summary: array<string, int>}  $walk  does the work, adding the ids it saved
     * @return array<string, mixed>
     */
    private function logged(CardholderSyncKind $kind, int $total, ?User $actor, Closure $walk): array
    {
        $sync = $this->syncs->create(['kind' => $kind, 'user_id' => $actor?->id, 'total' => $total, 'started_at' => now()]);
        $changed = ['created' => [], 'updated' => []];
        try {
            // Not an arrow function: the ids saved must reach this side, whatever happens
            $result = $this->webhooks->inBatch(self::SYNCED_EVENT, function () use ($walk, &$changed): array {
                return $walk($changed);
            });
            $counts = ['finished_at' => now()];
            foreach (self::COUNT_COLUMNS[$kind->value] as $counted => $column) {
                $counts[$column] = $result['summary'][$counted];
            }
            $this->syncs->update($sync, $counts);
        } finally {
            if ($changed['created'] !== [] || $changed['updated'] !== []) {
                $this->webhooks->tellSynced($sync, $changed);
            }
        }

        return ['id' => $sync->public_id, ...$result];
    }

    /**
     * @param  array<int, array<array-key, mixed>>  $records
     * @param  array{created: array<int, string>, updated: array<int, string>}  $changed  the ids saved so far, kept by the caller in case the run breaks off
     * @return array{dry_run: bool, summary: array<string, int>, records: array<int, array{index: int, employee_no: mixed, result: string, id: string|null, errors: object}>, lookups_created: array<int, string>}
     */
    private function walk(array $records, bool $dryRun, bool $createMissingLookups, ?User $actor, array &$changed = ['created' => [], 'updated' => []]): array
    {
        $seen = [];
        $results = [];
        $summary = ['total' => count($records), ...array_fill_keys(self::RESULTS, 0)];
        $lookupsCreated = [];

        foreach (array_values($records) as $index => $record) {
            $outcome = $dryRun
                ? $this->one($record, true, $createMissingLookups, $actor, $seen)
                : $this->sync->atomically(
                    // Not an arrow function: the numbers seen must outlive the record
                    function () use ($record, $createMissingLookups, $actor, &$seen): array {
                        return $this->one($record, false, $createMissingLookups, $actor, $seen);
                    },
                    fn (array $messages): array => ['messages' => $messages, 'errors' => ['record' => $messages], 'result' => 'failed', 'id' => null, 'lookups' => []],
                    'cardholders.sync.record_failed',
                );

            $result = $outcome['messages'] === [] ? $outcome['result'] : 'failed';
            $summary[$result]++;
            if ($result !== 'failed') {
                foreach ($outcome['lookups'] as $lookup) {
                    $lookupsCreated[$lookup] = true;
                }
            }
            if (! $dryRun && in_array($result, ['created', 'updated'], true) && $outcome['id'] !== null) {
                $changed[$result][] = $outcome['id'];
            }
            $results[] = [
                'index' => $index,
                'employee_no' => $record['employee_no'] ?? null,
                'result' => $result,
                'id' => $result === 'failed' ? null : $outcome['id'],
                'errors' => (object) ($result === 'failed' ? $outcome['errors'] : []),
            ];
        }

        return ['dry_run' => $dryRun, 'summary' => $summary, 'records' => $results, 'lookups_created' => array_keys($lookupsCreated)];
    }

    /**
     * @param  array<array-key, mixed>  $record
     * @param  array<string, true>  $seen
     * @return array{messages: array<int, string>, errors: array<string, array<int, string>>, result: string, id: string|null, lookups: array<int, string>}
     */
    private function one(array $record, bool $dryRun, bool $createMissingLookups, ?User $actor, array &$seen): array
    {
        $existing = $this->sync->find($record['employee_no'] ?? null);
        $mapped = $this->mapper->toInput($record, $createMissingLookups, $dryRun, $existing);
        $check = $this->sync->check($mapped['input'], $mapped['errors'], $existing, $seen, 'cardholders.sync.duplicate_in_batch', partial: true);
        $outcome = ['messages' => $check->messages, 'errors' => $check->errors, 'result' => $check->action(), 'id' => $check->existing?->public_id, 'lookups' => $mapped['created']];

        if (! $check->passes() || $dryRun) {
            return $outcome;
        }

        $saved = $this->sync->save($check, $actor);

        return [...$outcome, 'result' => $this->sync->result($check, $saved), 'id' => $saved->public_id];
    }

    /**
     * @param  array<int, string>  $employeeNos
     * @param  array{created: array<int, string>, updated: array<int, string>}  $changed  the ids deactivated so far, kept by the caller in case the run breaks off
     * @return array{dry_run: bool, summary: array<string, int>, records: array<int, array{index: int, employee_no: string, result: string, id: string|null, status: string|null, errors: object}>}
     */
    private function walkDeactivate(array $employeeNos, ?CardholderStatus $status, bool $dryRun, array &$changed = ['created' => [], 'updated' => []]): array
    {
        $seen = [];
        $results = [];
        $summary = ['total' => count($employeeNos), ...array_fill_keys(self::DEACTIVATE_RESULTS, 0)];

        foreach (array_values($employeeNos) as $index => $employeeNo) {
            $outcome = $this->sync->atomically(
                // Not an arrow function: the numbers seen must outlive the number
                function () use ($employeeNo, $status, $dryRun, &$seen): array {
                    return $this->leave($employeeNo, $status, $dryRun, $seen);
                },
                fn (array $messages): array => ['messages' => $messages, 'errors' => ['employee_no' => $messages], 'result' => 'failed', 'id' => null, 'status' => null],
                'cardholders.sync.record_failed',
            );

            $summary[$outcome['result']]++;
            if (! $dryRun && $outcome['result'] === 'deactivated' && $outcome['id'] !== null) {
                $changed['updated'][] = $outcome['id'];
            }
            $results[] = [
                'index' => $index,
                'employee_no' => $employeeNo,
                'result' => $outcome['result'],
                'id' => $outcome['id'],
                'status' => $outcome['status'],
                'errors' => (object) $outcome['errors'],
            ];
        }

        return ['dry_run' => $dryRun, 'summary' => $summary, 'records' => $results];
    }

    /**
     * One person who left. Only a number met twice fails (and rolls back nothing, as nothing was written);
     * a number no one has is told as not_found, with the reason.
     *
     * @param  array<string, true>  $seen
     * @return array{messages: array<int, string>, errors: array<string, array<int, string>>, result: string, id: string|null, status: string|null}
     */
    private function leave(string $employeeNo, ?CardholderStatus $status, bool $dryRun, array &$seen): array
    {
        $key = mb_strtolower(trim($employeeNo));
        if (isset($seen[$key])) {
            $message = __('cardholders.sync.duplicate_in_batch', ['value' => $employeeNo]);

            return ['messages' => [$message], 'errors' => ['employee_no' => [$message]], 'result' => 'failed', 'id' => null, 'status' => null];
        }
        $seen[$key] = true;

        $person = $this->sync->find($employeeNo);
        if ($person === null) {
            return ['messages' => [], 'errors' => ['employee_no' => [__('cardholders.sync.not_found', ['value' => $employeeNo])]], 'result' => 'not_found', 'id' => null, 'status' => null];
        }

        $keeps = $this->sync->keepsStatus($person, $status);
        if (! $keeps && ! $dryRun) {
            $keeps = ! $this->sync->deactivate($person, $status);
        }
        $after = $keeps ? $person->status : ($status ?? CardholderStatus::Inactive);

        return ['messages' => [], 'errors' => [], 'result' => $keeps ? 'unchanged' : 'deactivated', 'id' => $person->public_id, 'status' => $after->value];
    }
}
