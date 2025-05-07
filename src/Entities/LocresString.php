<?php

declare(strict_types=1);

namespace Diseltoofast\UeParser\Entities;

class LocresString
{
    public string $namespace;
    public int $namespaceHash;
    public string $key;
    public int $keyHash;
    public string $value;
    public int $valueHash;
    public int $count;

    public function __construct(string $namespace, int $namespaceHash, string $key, int $keyHash, string $value, int $valueHash, int $count = 1)
    {
        $this->namespace = $namespace;
        $this->namespaceHash = $namespaceHash;
        $this->key = $key;
        $this->keyHash = $keyHash;
        $this->value = $value;
        $this->valueHash = $valueHash;
        $this->count = $count;
    }
}
