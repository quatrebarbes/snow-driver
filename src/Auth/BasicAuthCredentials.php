<?php

namespace Quatrebarbes\SnowDriver\Auth;

use Illuminate\Http\Client\PendingRequest;

/**
 * EX-102 : authentification Basic Auth (MVP).
 */
class BasicAuthCredentials extends Credentials
{
    private const MASKED = '******';

    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
    ) {
    }

    public function username(): string
    {
        return $this->username;
    }

    public static function fromConfig(array $config): self
    {
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';

        if ($username === '' || $password === '') {
            throw new \InvalidArgumentException(
                "Les identifiants 'username' et 'password' sont requis pour l'authentification Basic Auth."
            );
        }

        return new self($username, $password);
    }

    public function applyTo(PendingRequest $request): PendingRequest
    {
        return $request->withBasicAuth($this->username, $this->password);
    }

    public function toLogArray(): array
    {
        return [
            'mode' => 'basic',
            'username' => $this->username,
            'password' => self::MASKED,
        ];
    }

    public function __debugInfo(): array
    {
        return $this->toLogArray();
    }
}
