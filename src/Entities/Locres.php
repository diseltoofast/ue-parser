<?php

declare(strict_types=1);

namespace Diseltoofast\UeParser\Entities;

/**
 * @property LocresString[] $strings
 */
class Locres
{
    public const MAGIC = '0E147475674A03FC4A15909DC3377F1B';
    public const FILE_VERSION = 3;

    public int $version;
    public int $stringsCount;
    public int $stringsWithoutDoubles;
    public array $strings = [];
}
