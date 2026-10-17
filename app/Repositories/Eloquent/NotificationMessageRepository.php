<?php

namespace App\Repositories\Eloquent;

use App\Data\Notifications\NotificationMessageFilter;
use App\Enums\NotificationStatus;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\NotificationMessage;
use App\Repositories\Contracts\NotificationMessageRepositoryInterface;
use App\Support\ListPage;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * @extends EloquentRepository<NotificationMessage>
 */
class NotificationMessageRepository extends EloquentRepository implements NotificationMessageRepositoryInterface
{
    /**
     * Whom and what a message was about, and who sent it (only what the log shows).
     */
    private const DETAILS = [
        'cardholder:id,public_id,full_name,employee_no',
        'familyMember:id,public_id,cardholder_id,name',
        'familyMember.cardholder:id,public_id,employee_no',
        'user:id,public_id,name',
        'card:id,public_id,number',
        'appointment:id,public_id,number',
        'sender:id,public_id,name',
        'resendOf:id,public_id',
        'resend:id,public_id,resend_of_id',
    ];

    protected function model(): string
    {
        return NotificationMessage::class;
    }

    public function deleteOlderThan(int $tenantId, CarbonInterface $before, int $chunk = 1000): int
    {
        $deleted = 0;
        do {
            $ids = NotificationMessage::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('created_at', '<', $before)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : NotificationMessage::query()->withoutGlobalScopes()->whereIn('id', $ids)->delete();
        } while ($ids->count() === $chunk);

        return $deleted;
    }

    public function findForDelivery(int $id): ?NotificationMessage
    {
        return NotificationMessage::query()->withoutGlobalScopes()->with('tenant')->find($id);
    }

    public function list(NotificationMessageFilter $filter): LengthAwarePaginator
    {
        return $this->ordered($filter)
            ->with(self::DETAILS)
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }

    public function reportRows(NotificationMessageFilter $filter): LazyCollection
    {
        return $this->ordered($filter)->with(self::DETAILS)->lazy(500);
    }

    public function reportTotals(NotificationMessageFilter $filter): array
    {
        // Only the criteria: no relations or counts beside an aggregate (MySQL's only_full_group_by)
        $totals = $this->criteria($filter)->toBase()
            ->selectRaw(
                'count(*) as messages, '
                .'coalesce(sum(case when status = ? then 1 else 0 end), 0) as sent, '
                .'coalesce(sum(case when status = ? then 1 else 0 end), 0) as failed, '
                .'coalesce(sum(case when status = ? then 1 else 0 end), 0) as skipped',
                [NotificationStatus::Sent->value, NotificationStatus::Failed->value, NotificationStatus::Skipped->value],
            )
            ->first();

        return [
            'messages' => (int) ($totals->messages ?? 0),
            'sent' => (int) ($totals->sent ?? 0),
            'failed' => (int) ($totals->failed ?? 0),
            'skipped' => (int) ($totals->skipped ?? 0),
        ];
    }

    public function lockForResend(NotificationMessage $message): NotificationMessage
    {
        return $this->query()->whereKey($message->id)->lockForUpdate()->with(['cardholder', 'familyMember', 'user', 'resend:id,resend_of_id'])->firstOrFail();
    }

    public function withDetails(NotificationMessage $message): NotificationMessage
    {
        return $message->load(self::DETAILS);
    }

    public function loggedValues(string $column): array
    {
        return $this->query()->distinct()->orderBy($column)->pluck($column)->all();
    }

    /**
     * The list's criteria in the list's order (the newer first on a tie), shared by the list and its report.
     *
     * @return Builder<NotificationMessage>
     */
    private function ordered(NotificationMessageFilter $filter): Builder
    {
        return $this->criteria($filter)
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->orderBy('id', 'desc');
    }

    /**
     * The organization's messages matching a filter, unordered: the list, its report and the report's totals.
     *
     * @return Builder<NotificationMessage>
     */
    public function criteria(NotificationMessageFilter $filter): Builder
    {
        $tenant = app(TenantContext::class);

        return $this->query()
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('address', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")
                ->orWhereHas('cardholder', fn (Builder $c) => $c->where('full_name', 'like', "%{$search}%")
                    ->orWhere('full_name_local', 'like', "%{$search}%")
                    ->orWhere('employee_no', 'like', "%{$search}%"))
                ->orWhereHas('familyMember', fn (Builder $f) => $f->where('name', 'like', "%{$search}%"))
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$search}%"))
                ->orWhereHas('card', fn (Builder $c) => $c->where('number', 'like', "%{$search}%"))))
            ->when($filter->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filter->channel, fn (Builder $q, string $channel) => $q->where('channel', $channel))
            ->when($filter->event, fn (Builder $q, string $event) => $q->where('event', $event))
            ->when($filter->cardholderPublicId, function (Builder $q, string $publicId) {
                $cardholder = Cardholder::query()->select('id')->where('public_id', $publicId);

                // To the cardholder, or to one of their family members
                return $q->where(fn (Builder $w) => $w
                    ->whereIn('cardholder_id', $cardholder)
                    ->orWhereIn('family_member_id', FamilyMember::query()->select('id')->whereIn('cardholder_id', $cardholder)));
            })
            ->when($filter->cardPublicId, fn (Builder $q, string $publicId) => $q->whereIn('card_id', Card::query()->select('id')->where('public_id', $publicId)))
            ->when($filter->dateFrom, fn (Builder $q, string $date) => $q->where('created_at', '>=', $tenant->dayStart($date)))
            ->when($filter->dateTo, fn (Builder $q, string $date) => $q->where('created_at', '<', $tenant->dayStart($date)->addDay()));
    }
}
