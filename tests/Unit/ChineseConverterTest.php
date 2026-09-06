<?php

namespace Tests\Unit;

use App\Support\ChineseConverter;
use PHPUnit\Framework\TestCase;

class ChineseConverterTest extends TestCase
{
    public function test_it_converts_esports_and_sports_terms_to_traditional_chinese(): void
    {
        $this->assertSame('英雄聯盟', ChineseConverter::toTraditional('英雄联盟'));
        $this->assertSame('無畏契約', ChineseConverter::toTraditional('无畏契约'));
        $this->assertSame('絕對武力', ChineseConverter::toTraditional('反恐精英'));
        $this->assertSame('比賽獲勝者 - Two 路線', ChineseConverter::toTraditional('比赛获胜者 - Two 路线'));
        $this->assertSame('比賽地圖數', ChineseConverter::toTraditional('比赛地图数'));
        $this->assertSame('一號地圖', ChineseConverter::toTraditional('一号地图'));
        $this->assertSame('未開始', ChineseConverter::toTraditional('未开始'));
        $this->assertSame('滾球中（1-1，三號地圖）', ChineseConverter::toTraditional('滚球中（1-1，三号地图）'));
        $this->assertSame('VCT 2026：太平洋賽區第二階段', ChineseConverter::toTraditional('VCT 2026：太平洋赛区第二阶段'));
    }

    public function test_it_handles_empty_and_english_strings(): void
    {
        $this->assertSame('', ChineseConverter::toTraditional(''));
        $this->assertSame('T1 Esports vs Dplus KIA', ChineseConverter::toTraditional('T1 Esports vs Dplus KIA'));
        $this->assertSame('Over 3.5', ChineseConverter::toTraditional('Over 3.5'));
    }
}
