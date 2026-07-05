<?php

namespace MadeByClowd\Documentable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Traits\Documentable;

class TestModel extends Model
{
    use Documentable;

    protected $table = 'test_models';

    protected $guarded = [];
}
