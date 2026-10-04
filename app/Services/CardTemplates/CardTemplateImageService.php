<?php

namespace App\Services\CardTemplates;

use App\Models\CardTemplate;
use App\Repositories\Contracts\CardTemplateRepositoryInterface;
use App\Support\Media\ImageSanitizer;
use App\Support\Media\UnreadableImageException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Logo and background images of a template (legacy fr_logo/bg_logo/bg_image uploads).
 * Each template keeps its own folder on the private disk; an image is referenced by its
 * random file name and shown through short-lived signed links.
 */
class CardTemplateImageService
{
    /**
     * Enough for a CR80 background at 600 dpi.
     */
    private const MAX_DIMENSION = 2400;

    public const NAME_PATTERN = '/^[0-9a-z]{26}\.(png|jpg)$/';

    public function __construct(
        private CardTemplateRepositoryInterface $templates,
        private ImageSanitizer $images,
    ) {}

    /**
     * Clean and store an upload. It stays unused until a layout save references it.
     */
    public function upload(CardTemplate $template, UploadedFile $upload): string
    {
        try {
            $image = $this->images->sanitize((string) $upload->getRealPath(), self::MAX_DIMENSION);
        } catch (UnreadableImageException) {
            throw ValidationException::withMessages(['file' => __('cardholder_files.unreadable_image')]);
        }

        $name = Str::lower((string) Str::ulid()).'.'.$image->extension;
        $this->disk()->put($this->path($template, $name), $image->contents);

        return $name;
    }

    public function exists(CardTemplate $template, string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1 && $this->disk()->exists($this->path($template, $name));
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        return (string) config('cardholder_files.disk');
    }

    public function path(CardTemplate $template, string $name): string
    {
        return $this->folder($template).'/'.$name;
    }

    public function url(CardTemplate $template, ?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'api.v1.card-template-images.show',
            now()->addMinutes((int) config('cardholder_files.url_ttl')),
            ['template' => $template->public_id, 'image' => basename($path)],
            absolute: false,
        );
    }

    /**
     * Give a copied template its own copies of the images, so later edits never touch the original.
     *
     * @return array<string, string> old path => new path
     */
    public function copyAll(CardTemplate $from, CardTemplate $to): array
    {
        $paths = [];
        foreach ($from->elements->pluck('image_path')->filter()->unique() as $path) {
            if ($this->disk()->exists($path)) {
                $paths[$path] = $this->path($to, basename($path));
                $this->disk()->copy($path, $paths[$path]);
            }
        }

        return $paths;
    }

    /**
     * @param  iterable<int, string>  $paths
     */
    public function delete(iterable $paths): void
    {
        foreach ($paths as $path) {
            $this->disk()->delete($path);
        }
    }

    public function deleteAll(CardTemplate $template): void
    {
        $this->disk()->deleteDirectory($this->folder($template));
    }

    /**
     * Stream an image for a signed link. The signature has already been verified.
     */
    public function stream(string $templatePublicId, string $name): StreamedResponse
    {
        $template = $this->templates->findAnyTenant($templatePublicId);

        if ($template === null || ! $this->exists($template, $name)) {
            throw new NotFoundHttpException;
        }

        return $this->disk()->response($this->path($template, $name), 'image', [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    private function folder(CardTemplate $template): string
    {
        return sprintf('tenants/%s/card-templates/%s', $template->tenant->public_id, $template->public_id);
    }
}
