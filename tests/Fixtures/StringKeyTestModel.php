<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Traits\Documentable;

/**
 * Owner whose primary key is a string (the shape a uuid or ulid owner has), used to pin that the int-key
 * relation fix leaves string-key owners alone.
 */
class StringKeyTestModel extends Model
{
    use Documentable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'string_key_test_models';

    protected $guarded = [];
}
