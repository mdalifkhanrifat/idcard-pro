<?php

namespace App\Http\Requests\Notifications;

use App\Data\Notifications\NotificationMessageFilter;
use App\Enums\NotificationStatus;
use App\Support\ListPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ListNotificationMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('notifications.view');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $sorts = collect(NotificationMessageFilter::SORTS)->flatMap(fn (string $s) => [$s, "-{$s}"])->all();

        return [
            'filter' => ['sometimes', 'array:search,status,channel,event,cardholder,card,date_from,date_to'],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', new Enum(NotificationStatus::class)],
            // Any key (meta lists those of the registry and those in the log, e.g. of a channel or event since removed)
            'filter.channel' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'filter.event' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'filter.cardholder' => ['nullable', 'string', 'size:26'],
            'filter.card' => ['nullable', 'string', 'size:26'],
            'filter.date_from' => ['nullable', 'date_format:Y-m-d'],
            'filter.date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filter.date_from'],
            'sort' => ['sometimes', Rule::in($sorts)],
            'per_page' => ListPage::rule(),
        ];
    }

    public function toFilter(): NotificationMessageFilter
    {
        return NotificationMessageFilter::fromArray($this->validated());
    }
}
