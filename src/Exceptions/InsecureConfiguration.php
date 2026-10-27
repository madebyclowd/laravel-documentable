<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Exceptions;

use RuntimeException;

/**
 * Thrown at boot when the package would serve its routes in production without an authorizer the app chose.
 */
class InsecureConfiguration extends RuntimeException
{
    public static function noAuthorizer(): self
    {
        return new self(
            "Documentable routes are mounted in production but 'documentable.authorization.resolver' is not set. "
            .'Production must name its authorizer: run `php artisan documents:make-authorizer` to scaffold one and set '
            .'that key to its class (use MadeByClowd\\Documentable\\Defaults\\GateDocumentAuthorizer::class to keep '
            ."the Gate-backed default), or set 'documentable.load_routes' to false and mount the controllers yourself."
        );
    }
}
