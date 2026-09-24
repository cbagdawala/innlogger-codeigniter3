<?php

namespace InnLogger\CodeIgniter3\Tests\Unit;

use InnLogger\CodeIgniter3\Redactor;
use InnLogger\CodeIgniter3\Tests\Support\FakeTransport;
use InnLogger\CodeIgniter3\Tests\Support\TestCase;

final class RedactionTest extends TestCase
{
    public function test_every_default_key_from_the_security_spec_is_redacted(): void
    {
        $keys = ['password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'authorization',
            'cookie', 'card_number', 'cvv', 'secret', 'api_secret'];
        $input = array_fill_keys($keys, 'sensitive');
        $input['safe'] = 'visible';

        $out = (new Redactor())->redact($input);

        foreach ($keys as $key) {
            $this->assertSame('[REDACTED]', $out[$key], $key);
        }
        $this->assertSame('visible', $out['safe']);
    }

    public function test_redaction_is_case_insensitive_and_recursive(): void
    {
        $input = [
            'user' => [
                'Password' => 'a',
                'profile' => ['ACCESS_TOKEN' => 'b', 'name' => 'Asha'],
                'cards' => [['Card_Number' => '4111', 'CVV' => '123', 'last4' => '1111']],
            ],
            'headers' => ['Authorization' => 'Bearer x', 'Cookie' => 'ci_session=1', 'Set-Cookie' => 'y', 'Accept' => 'json'],
        ];
        $out = (new Redactor())->redact($input);

        $this->assertSame('[REDACTED]', $out['user']['Password']);
        $this->assertSame('[REDACTED]', $out['user']['profile']['ACCESS_TOKEN']);
        $this->assertSame('Asha', $out['user']['profile']['name']);
        $this->assertSame('[REDACTED]', $out['user']['cards'][0]['Card_Number']);
        $this->assertSame('[REDACTED]', $out['user']['cards'][0]['CVV']);
        $this->assertSame('1111', $out['user']['cards'][0]['last4']);
        $this->assertSame('[REDACTED]', $out['headers']['Authorization']);
        $this->assertSame('[REDACTED]', $out['headers']['Cookie']);
        $this->assertSame('[REDACTED]', $out['headers']['Set-Cookie'], 'dashes match underscores');
        $this->assertSame('json', $out['headers']['Accept']);
    }

    public function test_a_sensitive_key_holding_an_array_is_masked_whole(): void
    {
        $out = (new Redactor())->redact(['token' => ['a' => 1]]);
        $this->assertSame('[REDACTED]', $out['token']);
    }

    public function test_custom_keys_are_redacted_case_insensitively(): void
    {
        $redactor = new Redactor(['national_id', 'X-Tenant-Key']);
        $out = $redactor->redact(['National_ID' => '1', 'deep' => ['x_tenant_key' => '2'], 'other' => '3']);

        $this->assertSame('[REDACTED]', $out['National_ID']);
        $this->assertSame('[REDACTED]', $out['deep']['x_tenant_key']);
        $this->assertSame('3', $out['other']);
    }

    public function test_client_redacts_context_metadata_and_custom_fields_before_sending(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['redact_fields' => ['iban']]);
        $client->error('Payment failed', [
            'request' => ['password' => 'p', 'IBAN' => 'DE00', 'amount' => 10],
            'metadata' => ['api_secret' => 'nope', 'gateway' => 'stripe'],
        ]);
        $body = $transport->requests[0]['body'];
        $p = $transport->payload();

        $this->assertSame('[REDACTED]', $p['context']['request']['password']);
        $this->assertSame('[REDACTED]', $p['context']['request']['IBAN']);
        $this->assertSame(10, $p['context']['request']['amount']);
        $this->assertSame('[REDACTED]', $p['metadata']['api_secret']);
        $this->assertSame('stripe', $p['metadata']['gateway']);
        $this->assertStringNotContainsString('DE00', $body);
        $this->assertStringNotContainsString('"p"', $body);
    }
}
