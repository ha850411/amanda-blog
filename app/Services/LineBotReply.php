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
     *         is_live?: bool,
     *         series_score?: ?string,
     *         score?: ?string,
     *         team1: string,
     *         team2: string,
     *         tournament: string,
     *         odds: ?array{
     *             team1: array{price: float, bookmaker: string},
     *             team2: array{price: float, bookmaker: string}
     *         },
     *         h2h?: ?array{
     *             sample_size: int,
     *             history_total: int,
     *             team1_wins: int,
     *             team2_wins: int,
     *             team1_games: int,
     *             team2_games: int,
     *             series?: array<int, array{
     *                 date: string,
     *                 format: string,
     *                 team1_score: int,
     *                 team2_score: int,
     *                 winner: 'team1'|'team2'
     *             }>
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
