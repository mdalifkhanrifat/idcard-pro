<?php

namespace App\Services\Signatories;

use App\Models\Signatory;
use App\Repositories\Contracts\MasterDataRepositoryInterface;
use App\Repositories\Contracts\SignatoryRepositoryInterface;
use App\Support\Media\ImageSanitizer;
use App\Support\Media\UnreadableImageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Officials whose signatures are printed on cards. Signature images live on the private
 * disk and are only reachable through short-lived signed links.
 */
class SignatoryService
{
    private const SIGNATURE_MAX_DIMENSION = 1000;

    public function __construct(
        private SignatoryRepositoryInterface $signatories,
        private MasterDataRepositoryInterface $masterData,
        private ImageSanitizer $images,
    ) {}

    /**
     * @return Collection<int, Signatory>
     */
    public function list(?string $search = null, ?bool $isActive = null): Collection
    {
        return $this->signatories->list($search, $isActive);
    }

    /**
     * @param  array<string, mixed>  $data  validated input; `authority_type_id` is a public id
     */
    public function create(array $data): Signatory
    {
        return $this->signatories->create($this->resolveAuthorityType($data))->load('authorityType');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Signatory $signatory, array $data): Signatory
    {
        return $this->signatories->update($signatory, $this->resolveAuthorityType($data))->load('authorityType');
    }

    public function delete(Signatory $signatory): void
    {
        $this->signatories->delete($signatory);
        $this->removeSignatureFile($signatory);
    }

    /**
     * Store a new signature image, replacing (and deleting) the previous one.
     */
    public function uploadSignature(Signatory $signatory, UploadedFile $upload): Signatory
    {
        try {
            $image = $this->images->sanitize((string) $upload->getRealPath(), self::SIGNATURE_MAX_DIMENSION);
        } catch (UnreadableImageException) {
            throw ValidationException::withMessages(['file' => __('cardholder_files.unreadable_image')]);
        }

        $disk = (string) config('cardholder_files.disk');
        $path = sprintf('tenants/%s/signatories/%s.%s', $signatory->tenant->public_id, Str::lower((string) Str::ulid()), $image->extension);
        Storage::disk($disk)->put($path, $image->contents);

        $previous = clone $signatory;
        $this->signatories->update($signatory, ['signature_disk' => $disk, 'signature_path' => $path]);
        $this->removeSignatureFile($previous);

        return $signatory->load('authorityType');
    }

    public function removeSignature(Signatory $signatory): Signatory
    {
        $previous = clone $signatory;
        $this->signatories->update($signatory, ['signature_disk' => null, 'signature_path' => null]);
        $this->removeSignatureFile($previous);

        return $signatory->load('authorityType');
    }

    public function signatureUrl(Signatory $signatory): ?string
    {
        if ($signatory->signature_path === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'api.v1.signatory-signatures.show',
            now()->addMinutes((int) config('cardholder_files.url_ttl')),
            // The path is part of the signature, so a replaced image invalidates old links.
            ['signatory' => $signatory->public_id, 'v' => substr(sha1($signatory->signature_path), 0, 12)],
            absolute: false,
        );
    }

    /**
     * Stream a signature for a signed link. The signature has already been verified.
     */
    public function streamSignature(string $publicId, string $version): StreamedResponse
    {
        $signatory = $this->signatories->findAnyTenant($publicId);

        if ($signatory?->signature_path === null
            || substr(sha1($signatory->signature_path), 0, 12) !== $version
            || ! Storage::disk((string) $signatory->signature_disk)->exists($signatory->signature_path)) {
            throw new NotFoundHttpException;
        }

        return Storage::disk((string) $signatory->signature_disk)->response($signatory->signature_path, 'signature', [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    private function removeSignatureFile(Signatory $signatory): void
    {
        if ($signatory->signature_path !== null) {
            Storage::disk((string) $signatory->signature_disk)->delete($signatory->signature_path);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveAuthorityType(array $data): array
    {
        if (array_key_exists('authority_type_id', $data)) {
            $data['authority_type_id'] = $data['authority_type_id'] ? $this->masterData->idForPublicId($data['authority_type_id']) : null;
        }

        return $data;
    }
}
