<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\ValueObject;

final readonly class Cut
{
    public const PROFILE_MASTER = 'video_master_trimming';
    public const PROFILE_BROADCASTABLE = 'video_broadcastable_trimming';
    public const PROFILE_MASTER_BROADCASTABLE = 'video_master_broadcastable_trimming';

    public function __construct(
        public float $start,
        public float $end,
        public array $title,
        public array $description,
        public string $comments,
        public bool $master,
        public bool $broadcastable,
        public ?string $picture = null,
    ) {}

    public function profiles(): array
    {
        return match (true) {
            $this->master && $this->broadcastable => [self::PROFILE_MASTER, self::PROFILE_BROADCASTABLE],
            $this->master => [self::PROFILE_MASTER],
            $this->broadcastable => [self::PROFILE_MASTER_BROADCASTABLE],
            default => [],
        };
    }
}
