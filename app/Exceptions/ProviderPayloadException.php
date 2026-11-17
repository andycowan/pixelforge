<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider returned a success response that is not a safe generation
 * object. Messages name the offending field, never echo the raw payload.
 */
final class ProviderPayloadException extends RuntimeException {}
