<?php

namespace Waar\MicroCombat;

final class NumericOverflow extends \OverflowException
{
    public function __construct()
    {
        parent::__construct('numeric_overflow');
    }
}
