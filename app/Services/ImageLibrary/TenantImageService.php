<?php

namespace App\Services\ImageLibrary;

use App\Exceptions\BusinessRuleException;
use App\Models\TenantImage;
use App\Repositories\Contracts\CardTemplateElementRepositoryInterface;
use App\Repositories\Contracts\CardTemplateRepositoryInterface;
use App\Repositories\Contracts\TenantImageRepositoryInterface;
use App\Support\Media\ImageSanitizer;
use App\Support\Media\UnreadableImageException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The organization's image library: logos and backgrounds uploaded once and linked from any
 * card template. Files never change; a new image can replace an old one in every template.
 */
class TenantImageService
{
    /**
     * Same limit as template images: enough for a CR80 background at 600 dpi.
     */
    private const MAX_DIMENSION = 2400;

    public function __construct(
        private TenantImageRepositoryInterface $images,
        private CardTemplateElementRepositoryInterface $elements,
        private CardTemplateRepositoryInterface $templates,
        private ImageSanitizer $sanitizer,
        private TenantContext $tenancy,
    ) {}

    /**
     * @return Collection<int, TenantImage>
     */
    public function list(?string $search = null): Collection
    {
        return $this->images->list($search);
    }

    public function upload(UploadedFile $upload, ?string $name): TenantImage
    {
        try {
            $image = $this->sanitizer->sanitize((string) $upload->getRealPath(), self::MAX_DIMENSION);
        } catch (UnreadableImageException) {
            throw ValidationException::withMessages(['file' => __('cardholder_files.unreadable_image')]);
        }

        $disk = (string) config('cardholder_files.disk');
        $path = sprintf('tenants/%s/library/%s.%s', $this->tenancy->get()?->public_id, Str::lower((string) Str::ulid()), $image->extension);
        Storage::disk($disk)->put($path, $image->contents);

        $name = trim((string) ($name ?: pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME)));

        return $this->images->create([
            'name' => mb_substr($name === '' ? __('images.untitled') : $name, 0, 100),
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $image->mimeType,
            'width' => $image->width,
            'height' => $image->height,
            'size' => strlen($image->contents),
        ]);
    }

    public function rename(TenantImage $image, string $name): TenantImage
    {
        return $this->images->update($image, ['name' => $name]);
    }

    public function delete(TenantImage $image): void
    {
        $templates = $this->elements->templatesUsingLibraryImage($image);
        if ($templates !== []) {
            throw new BusinessRuleException(__('images.in_use', ['templates' => implode(', ', $templates)]));
        }

        $this->images->delete($image);
        Storage::disk($image->disk)->delete($image->path);
    }

    /**
     * Link every template element using `$from` to `$to` (e.g. a new logo); each changed template gets a new version.
     *
     * @return int number of templates changed
     */
    public function replaceEverywhere(TenantImage $from, TenantImage $to): int
    {
        if ($from->is($to)) {
            throw new BusinessRuleException(__('images.same_image'));
        }

        return DB::transaction(function () use ($from, $to): int {
            $templateIds = $this->elements->replaceLibraryImage($from, $to);
            $this->templates->incrementVersions($templateIds);

            return count($templateIds);
        });
    }

    /**
     * Logos are not personal data and appear on many thumbnails, so the link stays the same for a day and is cached.
     */
    public function url(TenantImage $image): string
    {
        return URL::temporarySignedRoute(
            'api.v1.library-image-files.show',
            now()->startOfDay()->addDays(2),
            ['image' => $image->public_id, 'v' => substr(sha1($image->path), 0, 12)],
            absolute: false,
        );
    }

    /**
     * Stream an image for a signed link. The signature has already been verified.
     */
    public function stream(string $publicId, string $version): StreamedResponse
    {
        $image = $this->images->findAnyTenant($publicId);

        if ($image === null || substr(sha1($image->path), 0, 12) !== $version || ! Storage::disk($image->disk)->exists($image->path)) {
            throw new NotFoundHttpException;
        }

        return Storage::disk($image->disk)->response($image->path, 'image', [
            'Content-Type' => $image->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
