<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelNgrokServer\Contracts\Runtime;

interface NgrokRuntimeLifecycleFence
{
    /** @template T @param callable(): T $callback @return T */
    public function synchronized(callable $callback): mixed;
}
