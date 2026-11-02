<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\ValueObject;

final readonly class Cut
{
    public function __construct(
        public float $start,
        public float $end,
        public array $title,
        public array $description,
        public string $comments,
        public bool $broadcastable,
        public ?string $picture = null,
    ) {}

    public function profiles(): array
    {
        return [$this->broadcastable ? 'video_broadcastable_trimming' : 'video_master_trimming'];
    }
}
