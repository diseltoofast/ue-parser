<?php

declare(strict_types=1);

namespace Diseltoofast\UeParser\Entities;

class Uasset
{
    public const SIGNATURE = 'C1832A9E';
    public string $namespace;
    public array $strings = [];
    public array $comments = [];
}
