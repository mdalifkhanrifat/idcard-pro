<?php

namespace App\Services\Biometrics;

use App\Data\Biometrics\CaptureData;
use App\Exceptions\BusinessRuleException;
use App\Models\BiometricCapture;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\User;
use App\Repositories\Contracts\BiometricCaptureRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Biometric captures of cardholders and their family members (Phase 4). Every rule comes from the
 * organization's settings (which modalities, positions, drivers, quality, tries, family members) and
 * the registry in config/biometrics.php (formats, limits, reasons); nothing here names a modality.
 * Records cascade with the person; their encrypted files are removed here once the records are gone.
 */
class BiometricService
{
    public function __construct(
        private BiometricCaptureRepositoryInterface $captures,
        private BiometricVault $vault,
        private TenantContext $tenantContext,
        private BiometricStatusService $statuses,
    ) {}

    /**
     * Current captures of the person and whether they meet the organization's settings.
     *
     * @return array{captures: Collection<int, BiometricCapture>, status: array<string, mixed>}
     */
    public function overview(Cardholder $cardholder, ?FamilyMember $member = null): array
    {
        $captures = $this->captures->currentFor($cardholder, $member);

        return ['captures' => $captures, 'status' => $this->status($cardholder, $member, $captures)];
    }

    /**
     * @param  Collection<int, BiometricCapture>|null  $captures  current captures, when already loaded
     * @return array{enabled: bool, applies: bool, complete: bool, modalities: array<string, array<string, mixed>>}
     */
    public function status(Cardholder $cardholder, ?FamilyMember $member = null, ?Collection $captures = null): array
    {
        return BiometricRequirement::evaluate(
            $this->settings(),
            $captures ?? $this->captures->currentFor($cardholder, $member),
            $member !== null,
        );
    }

    /**
     * Keep a capture as the current one of its position; the previous one goes to history without its data.
     */
    public function capture(Cardholder $cardholder, ?FamilyMember $member, CaptureData $data, User $actor): BiometricCapture
    {
        $modality = $this->usableModality($member, $data->modality, $data->position);
        if (! in_array($data->driver, $modality['drivers'], true)) {
            throw ValidationException::withMessages(['driver' => __('biometrics.capture.driver_not_allowed')]);
        }
        [$width, $height] = $this->checkImage($data);
        $this->checkTemplate($data);
        $lowQuality = $this->checkQuality($data, $modality);

        $tenant = (string) $this->tenantContext->get()?->public_id;
        $image = $this->vault->put($tenant, $data->image);
        $template = $data->template !== null ? $this->vault->put($tenant, $data->template) : null;

        try {
            return $this->replaceCurrent($cardholder, $member, $data->modality, $data->position, [
                'status' => BiometricCapture::STATUS_CAPTURED,
                'driver' => $data->driver,
                'device' => $data->device,
                'disk' => $image['disk'],
                'image_path' => $image['path'],
                'image_format' => $data->imageFormat,
                'image_width' => $width ?? $data->width,
                'image_height' => $height ?? $data->height,
                'image_dpi' => $data->dpi,
                'template_path' => $template['path'] ?? null,
                'template_format' => $template !== null ? $data->templateFormat : null,
                'quality' => $data->quality,
                'low_quality' => $lowQuality,
                'attempts' => $data->attempts,
            ], $actor);
        } catch (Throwable $exception) {
            // Nothing refers to the new files if the record was not saved
            $this->vault->delete(collect([$image, ...($template !== null ? [$template] : [])]));
            throw $exception;
        }
    }

    /**
     * Record that a position cannot be captured (e.g. a missing finger), so the person is not held up for it.
     */
    public function markUnavailable(Cardholder $cardholder, ?FamilyMember $member, string $modality, string $position, string $reason, ?string $note, User $actor): BiometricCapture
    {
        $this->usableModality($member, $modality, $position);
        if (in_array($reason, (array) config('biometrics.unavailable_reasons_with_note'), true) && blank($note)) {
            throw ValidationException::withMessages(['note' => __('biometrics.capture.note_required')]);
        }

        return $this->replaceCurrent($cardholder, $member, $modality, $position, [
            'status' => BiometricCapture::STATUS_UNAVAILABLE,
            'unavailable_reason' => $reason,
            'note' => filled($note) ? trim((string) $note) : null,
        ], $actor);
    }

    /**
     * Delete a capture and its encrypted files.
     */
    public function delete(BiometricCapture $capture): void
    {
        $locations = $this->locationsOf($capture);
        $this->captures->delete($capture);
        $this->vault->delete($locations);
        $this->statuses->refresh($capture->cardholder, $capture->familyMember);
    }

    /**
     * Short-lived signed link to the decrypted image, for people who may see biometrics.
     */
    public function imageUrl(BiometricCapture $capture): ?string
    {
        if ($capture->image_path === null || ! (config("biometrics.image_types.{$capture->image_format}.displayable") ?? false)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'api.v1.biometric-images.show',
            now()->addMinutes((int) config('biometrics.url_ttl')),
            ['capture' => $capture->public_id],
            absolute: false,
        );
    }

    /**
     * The decrypted image behind a signed link; never cached.
     */
    public function imageResponse(string $publicId): Response
    {
        $capture = $this->captures->findAnyTenant($publicId);
        if ($capture === null || $capture->image_path === null || $capture->disk === null) {
            throw new NotFoundHttpException;
        }

        return response($this->vault->get($capture->disk, $capture->image_path), 200, [
            'Content-Type' => (string) (config("biometrics.image_types.{$capture->image_format}.mime") ?? 'application/octet-stream'),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Where a cardholder's (and their family members') biometric files are; read before the cardholder is deleted.
     *
     * @return Collection<int, array{disk: string, path: string}>
     */
    public function storedLocations(Cardholder $cardholder): Collection
    {
        return $this->captures->locationsFor($cardholder);
    }

    /**
     * Where a family member's biometric files are; read before the member is deleted.
     *
     * @return Collection<int, array{disk: string, path: string}>
     */
    public function storedLocationsOfMember(FamilyMember $member): Collection
    {
        return $this->captures->locationsForMember($member);
    }

    /**
     * @param  Collection<int, array{disk: string, path: string}>  $locations
     */
    public function deleteStored(Collection $locations): void
    {
        $this->vault->delete($locations);
    }

    /**
     * @return array{enabled: bool, modalities: array<string, array{mode: string, count: int, positions: array<int, string>, substitutes: bool, min_quality: int, max_attempts: int, drivers: array<int, string>}>, family_members: bool, required_before: array<int, string>, print_on_card: bool}
     */
    private function settings(): array
    {
        return BiometricSettings::normalize((array) ($this->tenantContext->get()?->settings['biometrics'] ?? []));
    }

    /**
     * The organization's settings for a modality, when the person and position may be captured at all.
     *
     * @return array{mode: string, count: int, positions: array<int, string>, substitutes: bool, min_quality: int, max_attempts: int, drivers: array<int, string>}
     */
    private function usableModality(?FamilyMember $member, string $modality, string $position): array
    {
        $settings = $this->settings();
        if (! $settings['enabled']) {
            throw new BusinessRuleException(__('biometrics.capture.disabled'));
        }
        if ($member !== null && ! $settings['family_members']) {
            throw new BusinessRuleException(__('biometrics.capture.not_for_family'));
        }
        $chosen = $settings['modalities'][$modality] ?? null;
        if ($chosen === null || $chosen['mode'] === 'off') {
            throw ValidationException::withMessages(['modality' => __('biometrics.capture.modality_off')]);
        }
        // Without stand-ins only the chosen positions; with them any position of the modality
        $allowed = $chosen['substitutes'] ? array_keys((array) config("biometrics.modalities.{$modality}.positions")) : $chosen['positions'];
        if (! in_array($position, $allowed, true)) {
            throw ValidationException::withMessages(['position' => __('biometrics.capture.position_not_allowed')]);
        }

        return $chosen;
    }

    /**
     * The image must be in a format of the modality and really be that format; returns its size when readable.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function checkImage(CaptureData $data): array
    {
        $type = config("biometrics.image_types.{$data->imageFormat}");
        if (! is_array($type) || ! in_array($data->imageFormat, (array) config("biometrics.modalities.{$data->modality}.image_formats"), true)) {
            throw ValidationException::withMessages(['image_format' => __('biometrics.capture.format_not_allowed')]);
        }
        $maxKb = (int) config('biometrics.max_image_kb');
        if (strlen($data->image) > $maxKb * 1024) {
            throw ValidationException::withMessages(['image' => __('biometrics.capture.image_too_large', ['kb' => $maxKb])]);
        }
        if (! str_starts_with(bin2hex(substr($data->image, 0, 8)), (string) $type['signature'])) {
            throw ValidationException::withMessages(['image' => __('biometrics.capture.image_invalid', ['format' => strtoupper($data->imageFormat)])]);
        }
        if (! $type['displayable']) {
            return [null, null];
        }
        $size = @getimagesizefromstring($data->image);
        if ($size === false) {
            throw ValidationException::withMessages(['image' => __('biometrics.capture.image_invalid', ['format' => strtoupper($data->imageFormat)])]);
        }

        return [(int) $size[0], (int) $size[1]];
    }

    private function checkTemplate(CaptureData $data): void
    {
        if ($data->template === null) {
            return;
        }
        $formats = (array) config("biometrics.modalities.{$data->modality}.template_formats");
        if ($formats === []) {
            throw ValidationException::withMessages(['template' => __('biometrics.capture.template_not_allowed')]);
        }
        if (! in_array($data->templateFormat, $formats, true)) {
            throw ValidationException::withMessages(['template_format' => __('biometrics.capture.format_not_allowed')]);
        }
        $maxKb = (int) config('biometrics.max_template_kb');
        if (strlen($data->template) > $maxKb * 1024) {
            throw ValidationException::withMessages(['template' => __('biometrics.capture.template_too_large', ['kb' => $maxKb])]);
        }
    }

    /**
     * Below the organization's minimum the station tries again; after the allowed tries the operator
     * may keep the best capture, marked as low quality. An unknown quality (an uploaded image) is kept.
     *
     * @param  array{min_quality: int, max_attempts: int}  $modality
     */
    private function checkQuality(CaptureData $data, array $modality): bool
    {
        if ($data->quality === null || $data->quality >= $modality['min_quality']) {
            return false;
        }
        $replace = ['quality' => $data->quality, 'min' => $modality['min_quality'], 'tries' => $data->attempts, 'left' => $modality['max_attempts'] - $data->attempts];
        if ($data->attempts < $modality['max_attempts']) {
            throw ValidationException::withMessages(['quality' => __('biometrics.capture.low_quality_retry', $replace)]);
        }
        if (! $data->acceptLowQuality) {
            throw ValidationException::withMessages(['quality' => __('biometrics.capture.low_quality_confirm', $replace)]);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function replaceCurrent(Cardholder $cardholder, ?FamilyMember $member, string $modality, string $position, array $attributes, User $actor): BiometricCapture
    {
        $previous = $this->captures->currentAt($cardholder, $member, $modality, $position);
        $oldLocations = $previous !== null ? $this->locationsOf($previous) : collect();

        $capture = DB::transaction(function () use ($cardholder, $member, $modality, $position, $attributes, $actor): BiometricCapture {
            $this->captures->markReplaced($cardholder, $member, $modality, $position);

            return $this->captures->create([
                ...$attributes,
                'cardholder_id' => $cardholder->id,
                'family_member_id' => $member?->id,
                'modality' => $modality,
                'position' => $position,
                'captured_by' => $actor->id,
                'captured_at' => now(),
                'current_key' => BiometricCapture::currentKey($cardholder->id, $member?->id, $modality, $position),
            ]);
        });
        $this->vault->delete($oldLocations);
        $this->statuses->refresh($cardholder, $member);

        return $capture->load('capturer');
    }

    /**
     * @return Collection<int, array{disk: string, path: string}>
     */
    private function locationsOf(BiometricCapture $capture): Collection
    {
        return collect([$capture->image_path, $capture->template_path])
            ->filter()
            ->map(fn (string $path): array => ['disk' => (string) $capture->disk, 'path' => $path])
            ->values();
    }
}
