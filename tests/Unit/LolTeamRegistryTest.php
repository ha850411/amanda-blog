<?php

namespace Tests\Unit;

use App\Services\LineSchedule\Support\TeamAbbreviation;
use Tests\TestCase;

class LolTeamRegistryTest extends TestCase
{
    public function test_2026_tier_one_roster_is_complete_and_aliases_do_not_collide(): void
    {
        // Independent roster checklist from Riot's 2026 League Handbook.
        $expected = [
            'LCK' => 'GEN T1 NS DNS BRO HLE DK KT BFX KRX',
            'LPL' => 'BLG AL TES IG JDG WBG NIP WE EDG TT LGD UP LNG OMG',
            'LEC' => 'GX G2 FNC KC KCB LR MKOI NAVI SHFT SK TH VIT',
            'LCS' => 'SEN C9 DIG DSG FLY LYON SR TLAW',
            'CBLOL' => 'FX FUR LEV LOS LOUD PAIN RED VKS',
            'LCP' => 'CFO DFM DCG GAM GZ MVK SHG TSW',
        ];
        $leagues = config('lol_teams.leagues');
        $this->assertSame(array_keys($expected), array_keys($leagues));
        $owners = [];
        foreach ($expected as $league => $codes) {
            $this->assertEqualsCanonicalizing(explode(' ', $codes), array_column($leagues[$league], 'code'));
            foreach ($leagues[$league] as $team) {
                $aliases = TeamAbbreviation::aliases($team['code']);
                foreach ([$team['name'], $team['code'], ...$team['aliases']] as $alias) {
                    $this->assertContains($alias, $aliases);
                    $this->assertContains($team['name'], TeamAbbreviation::aliases($alias));
                    $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $alias));
                    $this->assertSame($team['code'], $owners[$key] ?? $team['code'], 'Alias collision: '.$alias);
                    $owners[$key] = $team['code'];
                }
            }
        }
        $this->assertCount(60, array_merge(...array_values($leagues)));
        $this->assertNotContains('Karmine Corp Blue', TeamAbbreviation::aliases('KC'));
        $this->assertNotContains('Sentinels', TeamAbbreviation::aliases('100T'));
        $this->assertNotContains('Ground Zero Gaming', TeamAbbreviation::aliases('PSG'));
        $this->assertContains('BILIBILI GAMING', TeamAbbreviation::aliases('Bili'));
        $this->assertSame(['Bil'], TeamAbbreviation::aliases('Bil'));
    }
}
