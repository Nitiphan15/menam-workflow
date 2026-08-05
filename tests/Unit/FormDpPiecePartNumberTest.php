<?php

namespace Tests\Unit;

use App\Support\FormDP\PieceSalePolicy;
use PHPUnit\Framework\TestCase;

class FormDpPiecePartNumberTest extends TestCase
{
    /** @dataProvider piecePartNumberProvider */
    public function test_sp001_is_sold_as_pieces(string $partNumber): void
    {
        $this->assertTrue(PieceSalePolicy::appliesTo($partNumber));
    }

    public static function piecePartNumberProvider(): array
    {
        return [
            'exact' => ['SP-001'],
            'case insensitive' => ['sp-001'],
            'trimmed' => ['  SP-001  '],
        ];
    }

    public function test_other_parts_keep_their_existing_sale_unit_rule(): void
    {
        $this->assertFalse(PieceSalePolicy::appliesTo('SP-002'));
    }
}
