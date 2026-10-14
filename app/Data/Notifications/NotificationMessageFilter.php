<?php

namespace App\Data\Notifications;

/**
 * Listing criteria for the log of sent messages (the log page, a cardholder's messages and, later, its report).
 */
final readonly class NotificationMessageFilter
{
    public const SORTS = ['created_at', 'sent_at'];

    public function __construct(
        public ?string $search = null,
        public ?string $status = null,
        public ?string $channel = null,
        public ?string $event = null,
        /** A cardholder's messages: to them and to their family members */
        public ?string $cardholderPublicId = null,
        public ?string $cardPublicId = null,
        /** Days of the organization the message was logged on (the last one whole) */
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public string $sort = 'created_at',
        public bool $descending = true,
        public int $perPage = 25,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input): self
    {
        $filter = $input['filter'] ?? [];
        $sort = (string) ($input['sort'] ?? '-created_at');

        return new self(
            search: $filter['search'] ?? null,
            status: $filter['status'] ?? null,
            channel: $filter['channel'] ?? null,
            event: $filter['event'] ?? null,
            cardholderPublicId: $filter['cardholder'] ?? null,
            cardPublicId: $filter['card'] ?? null,
            dateFrom: $filter['date_from'] ?? null,
            dateTo: $filter['date_to'] ?? null,
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($input['per_page'] ?? 25),
        );
    }
}
