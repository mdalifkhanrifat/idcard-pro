<?php

namespace App\Support\Media;

use RuntimeException;

/**
 * The uploaded content is not a JPEG, PNG or WebP image that can be decoded.
 */
class UnreadableImageException extends RuntimeException {}
