<?php

namespace GetCandy\Opayo\Facades;

use GetCandy\Opayo\OpayoInterface;
use Illuminate\Support\Facades\Facade;

class Opayo extends Facade
{
    /**
     * {@inheritdoc}
     */
    protected static function getFacadeAccessor()
    {
        return OpayoInterface::class;
    }
}
