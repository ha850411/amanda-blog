<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;

/** Give each task its own request keys while sharing one Laravel HTTP pool. */
class ScheduleHttpPool extends Pool
{
    public function __construct(private readonly Pool $parent, private readonly string $prefix) {}

    public function as(string $key)
    {
        return $this->pool[$key] = $this->parent->as($this->prefix.':'.$key);
    }

    public function newRequest()
    {
        $this->pool[] = null;

        return $this->as((string) array_key_last($this->pool));
    }

    public function responseKey(string|int $key): string
    {
        return $this->prefix.':'.$key;
    }
}
