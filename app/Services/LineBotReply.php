<?php

namespace App\Services;

final readonly class LineBotReply
{
    /**
     * @param  array{
     *     title: string,
     *     subtitle: string,
     *     matches: array<int, array{
     *         start_time: string,
     *         format: string,
     *         team1: string,
     *         team2: string,
     *         tournament: string,
     *         odds: ?array{
     *             team1: array{price: float, bookmaker: string},
     *             team2: array{price: float, bookmaker: string}
     *         }
     *     }>
     * }|null  $imageData
     */
    public function __construct(
        public string $text,
        public ?string $linkUrl = null,
        public ?array $imageData = null,
    ) {}

    public function prefersImage(): bool
    {
        return $this->linkUrl !== null && $this->imageData !== null;
    }
}
