<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * The scanner could not give a verdict and the install refuses unscanned files. An upload in the request is
 * refused with this; a background verification treats it as a reason to retry, never as a verdict on the file.
 *
 * @internal
 */
class ScannerUnavailable extends ValidationException {}
