<?php

namespace Tests\Feature;

use App\Models\FormDIE\DieDamage;
use App\Models\Users\User;
use App\Services\FormDIE\DieDamageReportService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DieDamageCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost', 'database.workflow_connection' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');
        DB::purge('sqlite');
        $migration = require base_path('database/migrations/2026_09_07_000001_create_die_damages_table.php');
        $migration->up();
        $this->withoutMiddleware(\App\Http\Middleware\LogUserActivity::class);
    }

    private function login(): void
    {
        $user = \Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 42, 'name' => 'DIE Test', 'is_superadmin' => true]);
        $user->shouldReceive('hasRoleCode')->andReturn(false);
        $this->actingAs($user);
    }

    private function data(array $replace = []): array
    {
        return array_replace(['site' => 'W', 'die_code' => 'D0001', 'damaged_at' => '2024-01-05', 'reason' => 'แตก', 'die_type' => 'Diamond', 'production_kg' => 1000, 'price' => 9450], $replace);
    }

    public function test_guests_cannot_read_or_write_reports(): void
    {
        foreach (['/die-reports', '/die-reports/reports', '/die-reports/export', '/die-reports/create'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->postJson('/die-reports', $this->data())->assertUnauthorized();
        $this->postJson('/die-reports/lookup', $this->data())->assertUnauthorized();
    }

    public function test_repeated_die_events_update_conflicts_and_soft_delete(): void
    {
        $this->login();
        $this->post('/die-reports', $this->data())->assertRedirect();
        $this->post('/die-reports', $this->data(['damaged_at' => '2024-02-05']))->assertRedirect();
        $this->assertSame(2, DieDamage::count());
        $r = DieDamage::first();
        $this->assertSame('42', $r->created_by);
        $this->put('/die-reports/'.$r->id, $this->data(['version' => 1, 'price' => 100]))->assertRedirect();
        $this->putJson('/die-reports/'.$r->id, $this->data(['version' => 1, 'price' => 999]))->assertStatus(409);
        $this->assertEquals(100, $r->fresh()->price);
        $this->deleteJson('/die-reports/'.$r->id, ['version' => 1])->assertStatus(409);
        $this->delete('/die-reports/'.$r->id, ['version' => 2])->assertRedirect();
        $this->assertSame(1, DieDamage::count());
        $this->assertSame(2, DieDamage::withTrashed()->count());
        $this->putJson('/die-reports/'.$r->id, $this->data(['version' => 3]))->assertNotFound();
    }

    public function test_invalid_inputs_are_rejected_and_unknown_numbers_stay_null(): void
    {
        $this->login();
        $this->postJson('/die-reports', $this->data(['production_kg' => -1, 'site' => 'ALL']))->assertUnprocessable()->assertJsonValidationErrors(['site', 'production_kg']);
        $this->postJson('/die-reports', $this->data(['tensile_min' => 100, 'tensile_max' => 50]))->assertUnprocessable();
        $this->post('/die-reports', $this->data(['production_kg' => '', 'price' => '', 'tensile_max' => 50]))->assertRedirect();
        $r = DieDamage::first();
        $this->assertNull($r->production_kg);
        $this->assertNull((new DieDamageReportService())->metrics($r->toArray())['thb_kg']);
    }

    public function test_source_snapshot_is_server_owned_and_bound_to_event(): void
    {
        $this->login();
        $snapshot = ['source' => ['site' => 'W', 'die_code' => 'D0001', 'as_of' => '2024-01-05'], 'values' => ['workorder' => 'W24001']];
        $this->withSession(['die_damage_lookup' => $snapshot])->post('/die-reports', $this->data(['source_snapshot' => ['fake' => true]]))->assertRedirect();
        $r = DieDamage::first();
        $this->assertSame($snapshot, $r->source_snapshot);
        $this->put('/die-reports/'.$r->id, $this->data(['version' => 1, 'die_code' => 'D0002']))->assertRedirect();
        $this->assertNull($r->fresh()->source_snapshot);
    }

    public function test_pages_filters_and_excel_use_the_same_events(): void
    {
        $this->login();
        $this->post('/die-reports', $this->data())->assertRedirect();
        $this->post('/die-reports', $this->data(['site' => 'P', 'damaged_at' => '2025-01-05']))->assertRedirect();
        $this->get('/die-reports')->assertOk()->assertSee('D0001');
        $this->get('/die-reports/create')->assertOk()->assertSee('PCM / MFG');
        $this->get('/die-reports/1/edit')->assertOk()->assertSee('9450');
        foreach (array_keys(DieDamageReportService::REPORTS) as $report) {
            $this->get('/die-reports/reports?report='.$report.'&site=W&from=2024-01-01&to=2024-12-31')->assertOk()->assertViewHas('records', fn ($r) => $r->count() === 1);
        }
        $response = $this->get('/die-reports/export?site=W');
        $response->assertOk();
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->assertSame(2, $book->getActiveSheet()->getHighestRow());
        $this->assertSame('D0001', $book->getActiveSheet()->getCell('D2')->getValue());
        $this->getJson('/die-reports/reports?from=2024-02-01&to=2024-01-01')->assertUnprocessable();
        $this->get('/die-reports/reports?to=2024-12-31')->assertOk()->assertViewHas('records', fn ($r) => $r->count() === 1);
    }
}
