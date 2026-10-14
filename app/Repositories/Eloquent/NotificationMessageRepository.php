<?php

namespace App\Repositories\Eloquent;

use App\Data\Notifications\NotificationMessageFilter;
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

/**
 * @extends EloquentRepository<NotificationMessage>
 */
class NotificationMessageRepository extends EloquentRepository implements NotificationMessageRepositoryInterface
{
    /**
     * Whom and what a message was about, and who sent it (only what the log shows).
     */
    private const DETAILS = [
        'cardholder:id,public_id,full_name',
        'familyMember:id,public_id,cardholder_id,name',
        'familyMember.cardholder:id,public_id',
        'user:id,public_id,name',
        'card:id,public_id,number',
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
        return $this->criteria($filter)
            ->with(self::DETAILS)
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->orderBy('id', 'desc')
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
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
     * The organization's messages matching a filter, unordered: the list and (later) its report use the same.
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
