<?php

namespace App\Services\Cardholders;

use App\Data\Cards\ImportCardData;
use App\Enums\ImportStatus;
use App\Enums\TemplateAudience;
use App\Exceptions\BusinessRuleException;
use App\Jobs\ImportCardholders;
use App\Models\Cardholder;
use App\Models\CardholderImport;
use App\Models\CardTemplate;
use App\Models\User;
use App\Repositories\Contracts\CardholderImportRepositoryInterface;
use App\Repositories\Contracts\CardholderRepositoryInterface;
use App\Repositories\Contracts\CardTemplateRepositoryInterface;
use App\Services\Cards\CardIssueService;
use App\Services\CustomFields\CustomFieldService;
use App\Support\Spreadsheets\TabularFileReader;
use App\Support\Spreadsheets\UnreadableSpreadsheetException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Imports cardholders from CSV/XLSX in two steps: analyze (validate every row, nothing is
 * written) and run (a queued job creates or updates the valid rows). When asked, each row also
 * brings the card the person already has (legacy import: card no, issue date, last printing)
 * into a chosen template; a row is saved with its card or not at all.
 */
class CardholderImportService
{
    public function __construct(
        private CardholderImportRepositoryInterface $imports,
        private CardholderRepositoryInterface $cardholders,
        private CardholderService $cardholderService,
        private CardholderValidator $validator,
        private CustomFieldService $customFields,
        private CardholderImportMapper $mapper,
        private TabularFileReader $reader,
        private CardTemplateRepositoryInterface $templates,
        private CardIssueService $cardIssue,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @return LengthAwarePaginator<int, CardholderImport>
     */
    public function list(int $perPage = 10): LengthAwarePaginator
    {
        return $this->imports->latest($perPage);
    }

    /**
     * Store the file and validate every row without writing any cardholder.
     *
     * @param  array{create_missing_lookups?: bool, update_existing?: bool, import_cards?: bool, card_template?: string|null}  $options
     */
    public function analyze(UploadedFile $file, array $options, User $actor): CardholderImport
    {
        $extension = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $disk = (string) config('cardholder_files.disk');
        $path = $file->storeAs(
            "tenants/{$actor->tenant?->public_id}/imports",
            Str::lower((string) Str::ulid()).".{$extension}",
            $disk,
        );

        $importCards = (bool) ($options['import_cards'] ?? false);
        $template = $importCards ? $this->cardTemplate($options['card_template'] ?? null) : null;
        if ($importCards && $template === null) {
            Storage::disk($disk)->delete((string) $path);

            throw ValidationException::withMessages(['card_template' => __('cards.import.template_unavailable')]);
        }
        $options = [
            'create_missing_lookups' => (bool) ($options['create_missing_lookups'] ?? false),
            'update_existing' => (bool) ($options['update_existing'] ?? false),
            'import_cards' => $importCards,
            'card_template' => $template?->public_id,
        ];

        try {
            $result = $this->walk(Storage::disk($disk)->path((string) $path), $extension, $options, dryRun: true, template: $template);
        } catch (Throwable $e) {
            Storage::disk($disk)->delete((string) $path);

            throw $e;
        }

        return $this->imports->create([
            'user_id' => $actor->id,
            'original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
            'disk' => $disk,
            'path' => $path,
            'status' => ImportStatus::Analyzed,
            'options' => $options,
            'summary' => [
                ...$result['summary'],
                'card_template' => $template ? ['id' => $template->public_id, 'name' => $template->name, 'code' => $template->code] : null,
            ],
            'total_rows' => $result['total'],
            'valid_rows' => $result['valid'],
            'failed_count' => $result['total'] - $result['valid'],
            'errors' => $result['errors'],
        ])->load('user');
    }

    public function start(CardholderImport $import): CardholderImport
    {
        if ($import->status !== ImportStatus::Analyzed) {
            throw new BusinessRuleException(__('cardholders.import.already_started'));
        }
        if ($import->valid_rows === 0) {
            throw new BusinessRuleException(__('cardholders.import.nothing_to_import'));
        }

        $this->imports->update($import, ['status' => ImportStatus::Queued]);
        ImportCardholders::dispatch($import->id);

        return $import->refresh()->load('user');
    }

    /**
     * Run an import (called by the queued job inside the import's tenant).
     */
    public function process(CardholderImport $import): void
    {
        $this->imports->update($import, ['status' => ImportStatus::Processing, 'started_at' => now()]);

        try {
            $template = null;
            if ($import->options['import_cards'] ?? false) {
                $template = $this->cardTemplate($import->options['card_template'] ?? null)
                    ?? throw new BusinessRuleException(__('cards.import.template_unavailable'));
            }
            $result = $this->walk(
                Storage::disk($import->disk)->path((string) $import->path),
                pathinfo((string) $import->path, PATHINFO_EXTENSION),
                [...$import->options, 'import_cards' => $template !== null],
                dryRun: false,
                actor: $import->user,
                template: $template,
            );

            $this->imports->update($import, [
                'status' => ImportStatus::Completed,
                'created_count' => $result['created'],
                'updated_count' => $result['updated'],
                'failed_count' => $result['total'] - $result['created'] - $result['updated'],
                'errors' => $result['errors'],
                'summary' => [...($import->summary ?? []), 'created_lookups' => $result['summary']['lookups_to_create'], 'cards_result' => $result['summary']['cards']],
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->imports->update($import, ['status' => ImportStatus::Failed, 'finished_at' => now(), 'errors' => [['row' => 0, 'messages' => [$e->getMessage()]]]]);

            throw $e;
        } finally {
            $this->removeFile($import);
        }
    }

    public function markFailed(CardholderImport $import, string $message): void
    {
        $this->imports->update($import, ['status' => ImportStatus::Failed, 'finished_at' => now(), 'errors' => [['row' => 0, 'messages' => [$message]]]]);
        $this->removeFile($import);
    }

    /**
     * Discard an analyzed import that will not be run.
     */
    public function discard(CardholderImport $import): void
    {
        if (in_array($import->status, [ImportStatus::Queued, ImportStatus::Processing], true)) {
            throw new BusinessRuleException(__('cardholders.import.running'));
        }

        $this->removeFile($import);
        $this->imports->delete($import);
    }

    /**
     * A CSV with every importable column (and active custom fields) as headers.
     */
    public function template(): string
    {
        $headers = collect(config('cardholder_import.columns'))
            ->map(fn (array $aliases, string $field) => Str::ucfirst($aliases[0] ?? $field))
            ->values()
            ->merge($this->customFields->list('cardholder', true)->pluck('label'))
            ->merge(collect(config('cardholder_import.card_columns'))->map(fn (array $aliases) => Str::ucfirst($aliases[0]))->values())
            ->all();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers, ',', '"', '');
        rewind($handle);

        // Byte order mark so spreadsheet programs read UTF-8 (e.g. Bangla) correctly.
        return "\xEF\xBB\xBF".stream_get_contents($handle);
    }

    /**
     * Read, map and validate every row; outside a dry run also save the valid ones (with their card
     * when a template is given).
     *
     * @param  array{create_missing_lookups: bool, update_existing: bool, import_cards?: bool}  $options
     * @return array{total: int, valid: int, created: int, updated: int, errors: array<int, array{row: int, messages: array<int, string>}>, summary: array<string, mixed>}
     */
    private function walk(string $path, string $extension, array $options, bool $dryRun, ?User $actor = null, ?CardTemplate $template = null): array
    {
        $this->mapper->useCustomFields($this->customFields->list('cardholder', true));
        $maxRows = (int) config('cardholder_import.max_rows');
        $maxErrors = (int) config('cardholder_import.max_errors');

        $columns = null;
        $summary = ['columns' => [], 'ignored' => [], 'preview' => [], 'lookups_to_create' => [], 'card_columns' => [], 'cards' => ['imported' => 0, 'skipped' => 0]];
        $total = $valid = $created = $updated = 0;
        $errors = [];
        $seen = ['employee_no' => [], 'card_no' => []];
        $timezone = $this->tenantContext->get()->timezone ?? 'UTC';

        try {
            foreach ($this->reader->rows($path, $extension) as $number => $cells) {
                if ($columns === null) {
                    if (array_filter($cells) === []) {
                        continue;
                    }
                    $mapping = $this->mapper->mapHeaders($cells);
                    if (! in_array('full_name', $mapping['columns'], true)) {
                        throw ValidationException::withMessages(['file' => __('cardholders.import.name_column_missing')]);
                    }
                    $columns = $mapping['columns'];
                    $summary['ignored'] = $mapping['ignored'];
                    // Card columns are only used when cards are imported; otherwise they are listed as unused
                    $cardColumns = array_filter($columns, fn (string $target) => str_starts_with($target, 'card:'));
                    $summary['card_columns'] = array_map(fn (int $index) => $cells[$index], array_keys($cardColumns));
                    if ($template === null) {
                        $columns = array_diff_key($columns, $cardColumns);
                        $summary['ignored'] = [...$summary['ignored'], ...$summary['card_columns']];
                    }
                    $summary['columns'] = collect($columns)->map(fn (string $target, int $index) => ['header' => $cells[$index], 'field' => $target])->values()->all();

                    continue;
                }
                if (array_filter($cells, fn (string $cell) => $cell !== '') === []) {
                    continue;
                }
                if (++$total > $maxRows) {
                    throw ValidationException::withMessages(['file' => __('cardholders.import.too_many_rows', ['max' => $maxRows])]);
                }

                $outcome = $this->handleRow($cells, $columns, $options, $dryRun, $actor, $template, $timezone, $seen);
                foreach ($outcome['lookups'] as $lookup) {
                    $summary['lookups_to_create'][$lookup] = true;
                }

                if ($outcome['messages'] !== []) {
                    if (count($errors) < $maxErrors) {
                        $errors[] = ['row' => $number, 'messages' => $outcome['messages']];
                    }

                    continue;
                }

                $valid++;
                if ($outcome['action'] === 'updated') {
                    $updated++;
                } elseif ($outcome['action'] === 'created') {
                    $created++;
                }
                if ($outcome['card'] !== null) {
                    $summary['cards'][$outcome['card']]++;
                }
                if ($dryRun && count($summary['preview']) < (int) config('cardholder_import.preview_rows')) {
                    $summary['preview'][] = ['row' => $number, 'action' => $outcome['action'], 'card' => $outcome['card'], 'values' => $outcome['preview']];
                }
            }
        } catch (UnreadableSpreadsheetException) {
            throw ValidationException::withMessages(['file' => __('cardholders.import.unreadable')]);
        }

        if ($columns === null) {
            throw ValidationException::withMessages(['file' => __('cardholders.import.empty')]);
        }

        $summary['lookups_to_create'] = array_keys($summary['lookups_to_create']);

        return compact('total', 'valid', 'created', 'updated', 'errors', 'summary');
    }

    /**
     * @param  array<int, string>  $cells
     * @param  array<int, string>  $columns
     * @param  array{create_missing_lookups: bool, update_existing: bool}  $options
     * @param  array{employee_no: array<string, true>, card_no: array<string, true>}  $seen  values met earlier in the file
     * @return array{messages: array<int, string>, action: string, card: 'imported'|'skipped'|null, lookups: array<int, string>, preview: array<string, string>}
     */
    private function handleRow(array $cells, array $columns, array $options, bool $dryRun, ?User $actor, ?CardTemplate $template, string $timezone, array &$seen): array
    {
        $preview = [];
        foreach ($columns as $index => $target) {
            $preview[$target] = (string) ($cells[$index] ?? '');
        }

        $run = function () use ($cells, $columns, $options, $dryRun, $actor, $template, $timezone, &$seen, $preview): array {
            $mapped = $this->mapper->toInput($cells, $columns, $options['create_missing_lookups'], $dryRun);
            $input = $mapped['input'];
            $messages = array_values($mapped['errors']);

            $employeeNo = $input['employee_no'] ?? null;
            if ($employeeNo !== null) {
                if (isset($seen['employee_no'][mb_strtolower($employeeNo)])) {
                    $messages[] = __('cardholders.import.duplicate_in_file', ['value' => $employeeNo]);
                }
                $seen['employee_no'][mb_strtolower($employeeNo)] = true;
            }

            $existing = $options['update_existing'] && $employeeNo !== null ? $this->cardholders->findByEmployeeNo($employeeNo) : null;
            $validation = Validator::make($input, $this->validator->rules($existing), [], $this->validator->attributes());
            $messages = [...$messages, ...$validation->errors()->all()];
            foreach ($this->validator->parentErrors($input, $existing) as $field => $message) {
                if (! $validation->errors()->hasAny([$field, CardholderValidator::DEPENDENT_LOOKUPS[$field]]) && ! isset($mapped['errors'][$field])) {
                    $messages[] = $message;
                }
            }

            [$card, $cardAction] = [null, null];
            if ($template !== null) {
                $mappedCard = $this->mapper->toCard($cells, $columns, $timezone);
                $messages = [...$messages, ...array_values($mappedCard['errors'])];
                if ($mappedCard['errors'] === [] && ! $mappedCard['data']->isEmpty()) {
                    $card = $mappedCard['data'];
                    $check = $this->checkCard($card, $existing, $template, $seen);
                    $messages = [...$messages, ...$check['errors']];
                    $cardAction = $check['skip'] ? 'skipped' : 'imported';
                }
            }

            $action = $existing ? 'updated' : 'created';
            if ($messages === [] && ! $dryRun) {
                $cardholder = $existing instanceof Cardholder
                    ? $this->cardholderService->update($existing, $validation->validated())
                    : $this->cardholderService->create($validation->validated(), $actor);
                if ($card !== null && $template !== null && $cardAction === 'imported') {
                    $this->cardIssue->importCard($cardholder, $template, $card, $actor);
                }
            }

            return ['messages' => $messages, 'action' => $action, 'card' => $messages === [] ? $cardAction : null, 'lookups' => $mapped['created'], 'preview' => $preview];
        };

        if ($dryRun) {
            return $run();
        }

        // Each row commits on its own so one bad row does not undo the others,
        // and master data created for a row that then fails is rolled back with it.
        try {
            return DB::transaction(function () use ($run): array {
                $outcome = $run();
                if ($outcome['messages'] !== []) {
                    throw new RowFailed($outcome);
                }

                return $outcome;
            });
        } catch (RowFailed $failed) {
            return $failed->outcome;
        } catch (ValidationException $e) {
            // A rule that changed since the row was checked (e.g. another person got the card number meanwhile)
            return ['messages' => array_values($e->validator->errors()->all()), 'action' => 'failed', 'card' => null, 'lookups' => [], 'preview' => $preview];
        } catch (Throwable $e) {
            report($e);

            return ['messages' => [__('cardholders.import.row_failed')], 'action' => 'failed', 'card' => null, 'lookups' => [], 'preview' => $preview];
        }
    }

    /**
     * The card rules (CardIssueService::checkImport()), and the same card number twice in the file.
     *
     * @param  array{employee_no: array<string, true>, card_no: array<string, true>}  $seen
     * @return array{errors: array<int, string>, skip: bool}
     */
    private function checkCard(ImportCardData $card, ?Cardholder $existing, CardTemplate $template, array &$seen): array
    {
        $check = $this->cardIssue->checkImport($existing, $template, $card);
        if ($card->number !== null) {
            if (isset($seen['card_no'][$card->number])) {
                $check['errors'][] = __('cardholders.import.duplicate_card_in_file', ['value' => $card->number]);
            }
            $seen['card_no'][$card->number] = true;
        }

        return $check;
    }

    /**
     * An active template for cardholders (not family members) that imported cards can belong to.
     */
    private function cardTemplate(?string $publicId): ?CardTemplate
    {
        $template = $publicId === null ? null : $this->templates->findByPublicId($publicId);

        return $template !== null && $template->is_active && $template->audience !== TemplateAudience::FamilyMember ? $template : null;
    }

    private function removeFile(CardholderImport $import): void
    {
        if ($import->path !== null) {
            Storage::disk($import->disk)->delete($import->path);
            $this->imports->update($import, ['path' => null]);
        }
    }
}
