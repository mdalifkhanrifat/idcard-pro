<?php

namespace App\Services\Cardholders;

use App\Data\Cardholders\CardholderSyncCheck;
use App\Models\Cardholder;
use App\Models\User;
use App\Repositories\Contracts\CardholderRepositoryInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Brings one person in from any source (a file row, an HR system, the old system): found by employee
 * number, checked by the same rules as the form, then created or updated through CardholderService.
 * Each source first maps its own shape to the input (CardholderImportMapper for files); from there on
 * every source is treated the same.
 */
class CardholderSyncService
{
    public function __construct(
        private CardholderRepositoryInterface $cardholders,
        private CardholderService $cardholderService,
        private CardholderValidator $validator,
    ) {}

    /**
     * Check one record without writing anything.
     *
     * @param  array<string, mixed>  $input  mapped input; master data references are public ids
     * @param  array<string, string>  $mappingErrors  field => message from mapping; such a field gets no second message
     * @param  array<string, true>  $seen  employee numbers (lower case) met earlier in the same batch
     * @param  string  $duplicateMessage  translation key (with :value) for an employee number met twice
     */
    public function check(array $input, array $mappingErrors, bool $updateExisting, array &$seen, string $duplicateMessage = 'cardholders.import.duplicate_in_file'): CardholderSyncCheck
    {
        $messages = array_values($mappingErrors);

        $employeeNo = $input['employee_no'] ?? null;
        if ($employeeNo !== null) {
            $key = mb_strtolower((string) $employeeNo);
            if (isset($seen[$key])) {
                $messages[] = __($duplicateMessage, ['value' => $employeeNo]);
            }
            $seen[$key] = true;
        }

        $existing = $updateExisting && $employeeNo !== null ? $this->cardholders->findByEmployeeNo((string) $employeeNo) : null;
        $validation = Validator::make($input, $this->validator->rules($existing), [], $this->validator->attributes());
        $messages = [...$messages, ...$validation->errors()->all()];
        foreach ($this->validator->parentErrors($input, $existing) as $field => $message) {
            if (! $validation->errors()->hasAny([$field, CardholderValidator::DEPENDENT_LOOKUPS[$field]]) && ! isset($mappingErrors[$field])) {
                $messages[] = $message;
            }
        }

        return new CardholderSyncCheck($existing, $messages === [] ? $validation->validated() : [], $messages);
    }

    /**
     * Save a record that passed its check: update the cardholder found, or create a new one.
     */
    public function save(CardholderSyncCheck $check, ?User $actor): Cardholder
    {
        if (! $check->passes()) {
            throw new LogicException('A record that failed its check cannot be saved.');
        }

        return $check->existing instanceof Cardholder
            ? $this->cardholderService->update($check->existing, $check->validated)
            : $this->cardholderService->create($check->validated, $actor);
    }

    /**
     * Run one record in a transaction of its own, so a record that fails undoes only itself (with any
     * master data made for it) and never the records before it.
     *
     * @template TOutcome of array{messages: array<int, string>}
     *
     * @param  Closure(): TOutcome  $record  checks and saves; an outcome with messages is rolled back
     * @param  Closure(array<int, string>): TOutcome  $failed  the outcome when the record broke off
     * @param  string  $unexpected  translation key for an error that is not the record's fault
     * @return TOutcome
     */
    public function atomically(Closure $record, Closure $failed, string $unexpected = 'cardholders.import.row_failed'): array
    {
        try {
            return DB::transaction(function () use ($record): array {
                $outcome = $record();
                if ($outcome['messages'] !== []) {
                    throw new RowFailed($outcome);
                }

                return $outcome;
            });
        } catch (RowFailed $rolledBack) {
            /** @var TOutcome */
            return $rolledBack->outcome;
        } catch (ValidationException $e) {
            // A rule that changed since the record was checked (e.g. another person got the card number meanwhile)
            return $failed(array_values($e->validator->errors()->all()));
        } catch (Throwable $e) {
            report($e);

            return $failed([__($unexpected)]);
        }
    }
}
