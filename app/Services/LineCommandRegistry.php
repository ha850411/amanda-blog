<?php

namespace App\Services;

/** Command aliases and help destinations are shared by routing and the manual. */
class LineCommandRegistry
{
    public const COMMANDS = [
        'help' => ['label' => '使用說明', 'page' => 'overview', 'aliases' => ['h', 'help']],
        'schedule' => ['label' => '賽程查詢', 'page' => 'schedule', 'aliases' => ['match', 'matches', '賽程', 'schedule', 'lol', 'val', 'cs', 'cs2', 'mlb', 'nba']],
        'odds' => ['label' => '賠率查詢', 'page' => 'odds', 'aliases' => ['odds', '賠率']],
        'bets' => ['label' => '進行中投注', 'page' => 'bets', 'aliases' => ['bet', 'bets', 'stake', '投注']],
        'records' => ['label' => '投注紀錄與損益', 'page' => 'records', 'aliases' => ['r', 'record', 'records', 'history', 'pnl', '損益', '紀錄', '記錄']],
        'balance' => ['label' => '資金水位走勢', 'page' => 'balance', 'aliases' => ['balance', 'bal', 'equity', 'water', 'chart', '水位', '資金水位', '資金']],
    ];

    public static function parse(string $message): ?array
    {
        if (! preg_match('/^!(\S+)(?:\s+(.*))?$/u', $message, $matches)) {
            return null;
        }
        $command = mb_strtolower($matches[1]);
        foreach (self::COMMANDS as $group => $definition) {
            if (in_array($command, $definition['aliases'], true)) {
                return ['group' => $group, 'command' => $command, 'argument' => trim($matches[2] ?? ''), 'help_page' => $definition['page']];
            }
        }

        return null;
    }

    public static function names(string $group): string
    {
        return implode('｜', array_map(fn (string $name): string => '!'.$name, self::COMMANDS[$group]['aliases']));
    }
}
