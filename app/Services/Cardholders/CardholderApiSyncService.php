<?php

namespace App\Services\Cardholders;

use App\Models\User;

/**
 * An HR system's batch of people (POST /api/v1/sync/cardholders): each record is found by employee
 * number and created or updated on its own, by the same rules as everywhere else
 * (CardholderSyncService). One record failing never holds back the others. A dry run says what
 * would happen and writes nothing.
 */
class CardholderApiSyncService
{
    public const RESULTS = ['created', 'updated', 'unchanged', 'failed'];

    public function __construct(
        private CardholderSyncService $sync,
        private CardholderSyncMapper $mapper,
    ) {}

    /**
     * @param  array<int, array<array-key, mixed>>  $records
     * @return array{dry_run: bool, summary: array<string, int>, records: array<int, array{index: int, employee_no: mixed, result: string, id: string|null, errors: object}>, lookups_created: array<int, string>}
     */
    public function run(array $records, bool $dryRun, bool $createMissingLookups, ?User $actor): array
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
}
