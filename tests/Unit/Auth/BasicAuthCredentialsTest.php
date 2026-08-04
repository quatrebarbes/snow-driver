<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Auth;

use InvalidArgumentException;
use Quatrebarbes\SnowDriver\Auth\BasicAuthCredentials;
use Quatrebarbes\SnowDriver\Auth\Credentials;
use Quatrebarbes\SnowDriver\Tests\TestCase;

class BasicAuthCredentialsTest extends TestCase
{
    public function test_it_builds_from_config(): void
    {
        $credentials = Credentials::fromConfig([
            'mode' => 'basic',
            'username' => 'alice',
            'password' => 'secret',
        ]);

        $this->assertInstanceOf(BasicAuthCredentials::class, $credentials);
        $this->assertSame('alice', $credentials->username());
    }

    public function test_it_requires_a_username_and_password(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BasicAuthCredentials::fromConfig(['mode' => 'basic', 'username' => 'alice']);
    }

    public function test_unsupported_auth_mode_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Credentials::fromConfig(['mode' => 'oauth2']);
    }

    public function test_the_password_is_masked_in_log_and_debug_representations(): void
    {
        $credentials = new BasicAuthCredentials('alice', 'secret');

        $logArray = $credentials->toLogArray();

        $this->assertSame('alice', $logArray['username']);
        $this->assertStringNotContainsString('secret', print_r($logArray, true));

        ob_start();
        var_dump($credentials);
        $dump = ob_get_clean();

        $this->assertStringNotContainsString('secret', $dump);
    }

    public function test_a_rejected_auth_mode_does_not_leak_the_password_in_the_error_message(): void
    {
        try {
            Credentials::fromConfig(['mode' => 'oauth2', 'password' => 'topsecret']);
            $this->fail('Expected an InvalidArgumentException to be thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('topsecret', $e->getMessage());
        }
    }
}
