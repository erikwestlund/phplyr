<?php

declare(strict_types=1);

namespace Phibble;

enum Type: string
{
    case Float = 'float';
    case Int = 'int';
    case Bool = 'bool';
    case String = 'string';
    case Factor = 'factor';
    case Date = 'date';
}
