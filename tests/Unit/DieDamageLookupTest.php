<?php

namespace Tests\Unit;

use App\Repositories\FormDIE\DieUsageEquipmentRepository;
use App\Repositories\FormDIE\WoDetailRepository;
use App\Services\FormDIE\DieDamageLookupService;
use Mockery;
use Tests\TestCase;

class DieDamageLookupTest extends TestCase
{
    public function test_lookup_cuts_off_future_history_and_does_not_guess_ambiguous_bom(): void
    {
        $dies = Mockery::mock(DieUsageEquipmentRepository::class);
        $wo = Mockery::mock(WoDetailRepository::class);
        $source = Mockery::mock(\App\Repositories\FormDIE\DieDamageSourceRepository::class);
        $source->shouldReceive('master')->with('D1', 'pgsqlpcmp')->andReturn((object) ['ordered_size' => 2, 'present_diameter' => 3]);
        $dies->shouldReceive('getDieHistoryAsOf')->with('D1', 'pgsqlpcmp', '2024-06-01')->andReturn([
            (object) ['transdate' => '2025-01-01', 'prod_wo' => '+FUTURE', 'txn_type' => 'issue', 'transnumber' => 'EU2'],
            (object) ['transdate' => '2024-01-01', 'prod_wo' => '+W1', 'txn_type' => 'issue', 'transnumber' => 'EU1'],
        ]);
        $wo->shouldReceive('getHeader')->with('+W1', 'pgsqlmfgp')->andReturn((object) ['id' => 1]);
        $wo->shouldReceive('getBom')->andReturn([(object) ['fgrade' => '304', 'fsupplier' => 'A'], (object) ['fgrade' => '316', 'fsupplier' => 'A']]);
        $wo->shouldReceive('getReceives')->andReturn([]);
        $result = (new DieDamageLookupService($dies, $wo, $source))->lookup('P', 'D1', '2024-06-01', null);
        $this->assertSame(['+W1'], $result['workorders']);
        $this->assertSame(1, $result['values']['usage_count']);
        $this->assertSame('A', $result['values']['material_supplier']);
        $this->assertArrayNotHasKey('wire_grade', $result['values']);
        $this->assertArrayNotHasKey('production_kg', $result['values']);
        $this->assertArrayNotHasKey('current_size', $result['values']);
    }

    public function test_lookup_counts_every_issue_beyond_the_profile_row_limit(): void
    {
        $dies = Mockery::mock(DieUsageEquipmentRepository::class);
        $source = Mockery::mock(\App\Repositories\FormDIE\DieDamageSourceRepository::class);
        $source->shouldReceive('master')->andReturn((object) []);
        $dies->shouldReceive('getDieProfile')->never();
        $history = [];
        for ($i = 1; $i <= 250; $i++) {
            $history[] = (object) ['transdate' => '2024-01-01', 'prod_wo' => null, 'txn_type' => 'issue', 'transnumber' => "EU{$i}"];
        }
        $dies->shouldReceive('getDieHistoryAsOf')->andReturn($history);

        $result = (new DieDamageLookupService($dies, Mockery::mock(WoDetailRepository::class), $source))->lookup('W', 'D1', '2024-06-01', null);

        $this->assertSame(250, $result['values']['usage_count']);
    }
}
