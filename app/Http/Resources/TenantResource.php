<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use App\Services\Biometrics\BiometricSettings;
use App\Services\Cards\CardNumberFormat;
use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'legal_name' => $this->legal_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country_code' => $this->country_code,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'primary_color' => $this->primary_color,
            'logo_url' => $this->logo_path ? url('storage/'.$this->logo_path) : null,
            'card_number_format' => $this->settings['card_number_format'] ?? CardNumberFormat::DEFAULT,
            'default_card_size' => $this->resource->defaultCardSize(),
            'card_number_on_reissue' => $this->resource->cardNumberOnReissue(),
            'verification' => $this->resource->verificationSettings(),
            // Details the public verification page may show (config), for the settings form
            'verification_fields' => config('cards.verification.fields'),
            'biometrics' => $this->resource->biometricSettings(),
            // What this installation can capture and with what, for the settings form and the stations
            'biometric_options' => [
                'modalities' => collect((array) config('biometrics.modalities'))->map(fn (array $modality, string $name): array => [
                    'positions' => array_keys($modality['positions']),
                    // Layout for screens (e.g. a row per hand), offered positions only
                    'groups' => collect((array) ($modality['groups'] ?? []))
                        ->map(fn (array $positions): array => array_values(array_intersect($positions, array_keys($modality['positions']))))
                        ->filter()->all(),
                    'printable' => (bool) $modality['printable'],
                    // What a capture may be sent as, e.g. for the file picker of the upload driver
                    'image_formats' => array_values((array) $modality['image_formats']),
                    'template_formats' => array_values((array) $modality['template_formats']),
                    'drivers' => BiometricSettings::driversFor($name),
                ])->all(),
                'drivers' => BiometricSettings::drivers(),
                'modes' => config('biometrics.modes'),
                'required_before' => config('biometrics.required_before'),
                // Which image a card prints: the one kept at issue or the latest at print
                'print_sources' => config('biometrics.print_sources'),
                'unavailable_reasons' => config('biometrics.unavailable_reasons'),
                // Reasons that need a note saying what happened
                'unavailable_reasons_with_note' => config('biometrics.unavailable_reasons_with_note'),
                // How a station recognizes an image file (first bytes) and whether a browser can show it
                'image_types' => config('biometrics.image_types'),
                'max_image_kb' => (int) config('biometrics.max_image_kb'),
                'max_template_kb' => (int) config('biometrics.max_template_kb'),
            ],
            'notifications' => $this->resource->notificationSettings(),
            // What this installation can send and about what, for the settings form: every event with the
            // placeholders its texts may use and its default texts in each language
            'notification_options' => [
                'channels' => NotificationSettings::channels(),
                'languages' => NotificationSettings::languages(),
                'log_days' => config('notifications.log_days'),
                'max_length' => config('notifications.max_length'),
                'events' => collect(NotificationSettings::events())->map(fn (NotificationEvent $event, string $key): array => [
                    'name' => (string) __("notifications.events.{$key}.name"),
                    'description' => (string) __("notifications.events.{$key}.description"),
                    // Read as a list: placeholder names hold dots, which __() would take as nesting
                    'placeholders' => array_map(fn (string $placeholder): array => ['key' => $placeholder, 'label' => (string) (((array) __('notifications.placeholders'))[$placeholder] ?? $placeholder)], $event->placeholders()),
                    'default_channels' => $event->defaultChannels(),
                    'default_templates' => collect(NotificationSettings::languages())->mapWithKeys(fn (string $language): array => [$language => collect(NotificationSettings::PARTS)->mapWithKeys(
                        fn (string $part): array => [$part => (string) __("notifications.events.{$key}.{$part}", locale: $language)],
                    )->all()])->all(),
                ])->all(),
            ],
            'status' => $this->status,
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at,
        ];
    }
}
