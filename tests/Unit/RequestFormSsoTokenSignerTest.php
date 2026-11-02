<?php

namespace Tests\Unit;

use App\Services\RequestFormSsoTokenSigner;
use RuntimeException;
use Tests\TestCase;

class RequestFormSsoTokenSignerTest extends TestCase
{
    private string $privateKeyPath;
    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $options = [
            'config' => 'C:\\xampp\\apache\\conf\\openssl.cnf',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $key = openssl_pkey_new($options);
        $privateKey = '';
        openssl_pkey_export($key, $privateKey, null, $options);
        $details = openssl_pkey_get_details($key);

        $this->privateKeyPath = tempnam(sys_get_temp_dir(), 'menam-sso-key-');
        file_put_contents($this->privateKeyPath, $privateKey);
        $this->publicKey = $details['key'];

        config()->set('services.request_form', [
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

    public function test_it_signs_short_lived_rs256_token_with_required_claims(): void
    {
        $token = app(RequestFormSsoTokenSigner::class)->sign($this->identity());
        [$encodedHeader, $encodedClaims, $encodedSignature] = explode('.', $token);
        $header = $this->decode($encodedHeader);
        $claims = $this->decode($encodedClaims);

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('menam-test-1', $header['kid']);
        $this->assertSame('menam-workflow', $claims['iss']);
        $this->assertSame('request-form-system', $claims['aud']);
        $this->assertSame('101', $claims['sub']);
        $this->assertSame('user@menamstainless.co.th', $claims['email']);
        $this->assertSame('Sales', $claims['department']);
        $this->assertSame(120, $claims['exp'] - $claims['iat']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $claims['jti']);

        $signature = $this->base64UrlDecode($encodedSignature);
        $this->assertSame(1, openssl_verify(
            $encodedHeader . '.' . $encodedClaims,
            $signature,
            $this->publicKey,
            OPENSSL_ALGO_SHA256
        ));
    }

    public function test_it_rejects_inactive_or_external_email_users(): void
    {
        $signer = app(RequestFormSsoTokenSigner::class);

        try {
            $signer->sign($this->identity(['active' => false]));
            $this->fail('Inactive identity was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Inactive', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $signer->sign($this->identity(['email' => 'user@example.com']));
    }

    public function test_it_rejects_ttl_longer_than_two_minutes(): void
    {
        config()->set('services.request_form.sso_ttl_seconds', 121);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TTL');
        app(RequestFormSsoTokenSigner::class)->sign($this->identity());
    }

    private function identity(array $overrides = []): array
    {
        return array_merge([
            'id' => 101,
            'email' => ' User@MenamStainless.co.th ',
            'name' => 'SSO User',
            'department' => 'Sales',
            'active' => true,
        ], $overrides);
    }

    private function decode(string $value): array
    {
        return json_decode($this->base64UrlDecode($value), true);
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4));
    }
}
