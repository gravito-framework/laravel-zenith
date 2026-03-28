<?php

namespace Gravito\Zenith\Laravel\Support;

trait GeneratesWorkerId
{
    protected function generateWorkerId(): string
    {
        return gethostname() . '-' . getmypid();
    }
}
