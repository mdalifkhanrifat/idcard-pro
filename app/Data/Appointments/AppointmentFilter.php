<?php

namespace App\Data\Appointments;

/**
 * Listing criteria for appointments (the staff's list, a cardholder's appointments and, later, their report).
 */
final readonly class AppointmentFilter
{
    public const SORTS = ['date', 'number', 'created_at'];

    public function __construct(
        /** The number, the cardholder's name, local name or employee number, or the family member's name */
        public ?string $search = null,
        /** One appointment by its number exactly (any day — checking in by number or QR, another system's lookup) */
        public ?string $number = null,
        /** Days of the organization (both whole) */
        public ?string $from = null,
        public ?string $to = null,
        /** Without a range: also those of past days (otherwise from the organization's today on) */
        public bool $past = false,
        public ?string $locationPublicId = null,
        /** @var array<int, string> statuses, any of them */
        public array $statuses = [],
        /** A kind of visit's key (also one since removed from the settings) */
        public ?string $service = null,
        public ?string $source = null,
        /** A cardholder's appointments: their own and their family members' */
        public ?string $cardholderPublicId = null,
        public ?string $familyMemberPublicId = null,
        public string $sort = 'date',
        public bool $descending = false,
        public int $perPage = 25,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input): self
    {
        $filter = $input['filter'] ?? [];
        $sort = (string) ($input['sort'] ?? 'date');

        return new self(
            search: $filter['search'] ?? null,
            number: isset($filter['number']) ? trim((string) $filter['number']) : null,
            from: $filter['from'] ?? null,
            to: $filter['to'] ?? null,
            past: filter_var($filter['past'] ?? false, FILTER_VALIDATE_BOOL),
            locationPublicId: $filter['location'] ?? null,
            statuses: array_values(array_unique((array) ($filter['status'] ?? []))),
            service: $filter['service'] ?? null,
            source: $filter['source'] ?? null,
            cardholderPublicId: $filter['cardholder'] ?? null,
            familyMemberPublicId: $filter['family_member'] ?? null,
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($input['per_page'] ?? 25),
        );
    }
}
