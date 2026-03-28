<?php

namespace Gravito\Zenith\Laravel\DataTransferObjects;

final class LogEntry
{
    public function __construct(
        public readonly string $level,
        public readonly string $message,
        public readonly string $workerId,
        public readonly string $timestamp,
        public readonly array $context = [],
    ) {}

    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'message' => $this->message,
            'workerId' => $this->workerId,
            'timestamp' => $this->timestamp,
            'context' => $this->context,
        ];
    }
}
