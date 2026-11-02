<?php

namespace App\Services;

use RuntimeException;

class RequestFormSsoTokenSigner
{
    public function sign(array $identity): string
    {
        $this->validateIdentity($identity);

        $keyId = trim((string) config('services.request_form.sso_key_id'));
        if ($keyId === '') {
            throw new RuntimeException('Request Form SSO key ID is not configured.');
        }

        $ttl = (int) config('services.request_form.sso_ttl_seconds', 120);
        if ($ttl < 1 || $ttl > 120) {
            throw new RuntimeException('Request Form SSO TTL must be between 1 and 120 seconds.');
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $keyId];
        $claims = [
            'iss' => (string) config('services.request_form.sso_issuer'),
            'aud' => (string) config('services.request_form.sso_audience'),
            'sub' => (string) $identity['id'],
            'email' => strtolower(trim((string) $identity['email'])),
            'name' => trim((string) $identity['name']),
            'department' => trim((string) ($identity['department'] ?? '')),
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(24)),
        ];

        $encodedHeader = $this->base64Url(json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedClaims = $this->base64Url(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $privateKey = openssl_pkey_get_private($this->readPrivateKey());
        if ($privateKey === false) {
            throw new RuntimeException('Request Form SSO private key is invalid.');
        }

        $signed = openssl_sign(
            $encodedHeader . '.' . $encodedClaims,
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256
        );
        if (!$signed) {
            throw new RuntimeException('Unable to sign Request Form SSO token.');
        }

        return $encodedHeader . '.' . $encodedClaims . '.' . $this->base64Url($signature);
    }

    private function validateIdentity(array $identity): void
    {
        if (empty($identity['active'])) {
            throw new RuntimeException('Inactive users cannot use Request Form SSO.');
        }
        if (!isset($identity['id']) || (int) $identity['id'] < 1) {
            throw new RuntimeException('Invalid Menam user ID.');
        }

        $email = strtolower(trim((string) ($identity['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid company email is required for Request Form SSO.');
        }
        $domain = substr(strrchr($email, '@') ?: '', 1);
        if (!in_array($domain, (array) config('services.request_form.sso_allowed_email_domains', []), true)) {
            throw new RuntimeException('Email domain is not allowed for Request Form SSO.');
        }
    }

    private function readPrivateKey(): string
    {
        $configuredPath = trim((string) config('services.request_form.sso_private_key_path'));
        if ($configuredPath === '') {
            throw new RuntimeException('Request Form SSO private key path is not configured.');
        }

        $path = str_starts_with($configuredPath, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $configuredPath)
            ? $configuredPath
            : base_path($configuredPath);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Request Form SSO private key is unavailable.');
        }

        return (string) file_get_contents($path);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
