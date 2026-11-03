<?php

namespace Tests\Feature;

use App\Models\Users\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RequestFormSsoHardeningTest extends TestCase
{
    private string $privateKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        // APP_URL may carry the Apache sub-path, which test requests do not have.
        URL::forceRootUrl('http://localhost');

        // phpunit.xml leaves the real connections active: everything here must stay on this in-memory database.
        config()->set('database.connections.sso_testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()->set('database.workflow_connection', 'sso_testing');
        config()->set('database.connections.sqlsrv_menam', config('database.connections.sso_testing'));
        DB::purge('sso_testing');
        DB::purge('sqlsrv_menam');

        foreach (['sso_testing', 'sqlsrv_menam'] as $connection) {
            Schema::connection($connection)->create('users', function (Blueprint $table) {
                $table->id();
                $table->string('username')->nullable();
                $table->string('name');
                $table->string('email');
                $table->string('phone')->nullable();
                $table->string('password');
                $table->unsignedBigInteger('department_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->rememberToken();
                $table->timestamps();
            });
            Schema::connection($connection)->create('departments', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }

        $options = [
            'config' => 'C:\\xampp\\apache\\conf\\openssl.cnf',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $key = openssl_pkey_new($options);
        $privateKey = '';
        openssl_pkey_export($key, $privateKey, null, $options);
        $this->privateKeyPath = tempnam(sys_get_temp_dir(), 'menam-sso-key-');
        file_put_contents($this->privateKeyPath, $privateKey);

        config()->set('services.request_form', [
            'url' => '/request-form-system/requests/create',
            'sso_enabled' => true,
            'sso_url' => 'https://forms.example.test/sso/menam/consume',
            'sso_issuer' => 'menam-workflow',
            'sso_audience' => 'request-form-system',
            'sso_key_id' => 'menam-test-1',
            'sso_private_key_path' => $this->privateKeyPath,
            'sso_ttl_seconds' => 120,
            'sso_allowed_email_domains' => ['menamstainless.co.th'],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->privateKeyPath) && is_file($this->privateKeyPath)) {
            unlink($this->privateKeyPath);
        }

        parent::tearDown();
    }

    public function test_active_user_receives_non_cacheable_handoff_page(): void
    {
        $response = $this->actingAs($this->user())->get(route('request-form.sso'));

        $response->assertOk();
        $response->assertSee('https://forms.example.test/sso/menam/consume', false);
        $response->assertSee('name="token"', false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function test_inactive_user_is_redirected_home_without_token(): void
    {
        $response = $this->actingAs($this->user(['is_active' => 0]))->get(route('request-form.sso'));

        $response->assertRedirect(route('home'));
        $response->assertSessionHas('error');
    }

    public function test_plain_http_destination_is_refused_in_production(): void
    {
        config()->set('services.request_form.sso_url', 'http://forms.example.test/sso/menam/consume');
        $user = $this->user();
        $this->app['env'] = 'production';

        $response = $this->actingAs($user)->get(route('request-form.sso'));

        $response->assertRedirect(route('home'));
        $response->assertDontSee('name="token"', false);
    }

    public function test_user_cannot_change_own_email_from_profile(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->put(route('profile.update'), [
            'name' => 'SSO User',
            'email' => 'admin@menamstainless.co.th',
            'phone' => '0812345678',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('sso.user@menamstainless.co.th', $user->fresh()->email);
        $this->assertSame('0812345678', $user->fresh()->phone);
    }

    private function user(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'sso.user',
            'name' => 'SSO User',
            'email' => 'sso.user@menamstainless.co.th',
            'password' => 'not-used',
            'is_active' => 1,
        ], $overrides));
    }
}
