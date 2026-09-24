<?php

namespace InnLogger\CodeIgniter3\Tests\Unit;

use InnLogger\CodeIgniter3\Level;
use InnLogger\CodeIgniter3\Signer;
use InnLogger\CodeIgniter3\Uuid;
use PHPUnit\Framework\TestCase;

final class LevelAndSignerTest extends TestCase
{
    public function test_level_names_match_the_severity_scale(): void
    {
        $expected = [0 => 'OFF', 1 => 'CRITICAL', 2 => 'ERROR', 3 => 'WARNING', 4 => 'NOTICE', 5 => 'INFO', 6 => 'DEBUG', 7 => 'TRACE'];
        foreach ($expected as $value => $name) {
            $this->assertSame($name, Level::name($value));
        }
    }

    public function test_level_from_accepts_ints_numeric_strings_and_names(): void
    {
        $this->assertSame(2, Level::from(2));
        $this->assertSame(5, Level::from('5'));
        $this->assertSame(3, Level::from('Warning'));
        $this->assertSame(1, Level::from('emergency'));
        $this->assertSame(7, Level::from('all'));
        $this->assertSame(0, Level::from('off'));
        $this->assertNull(Level::from(8));
        $this->assertNull(Level::from(-1));
        $this->assertNull(Level::from('verbose'));
        $this->assertNull(Level::from(2.0));
    }

    public function test_passes_implements_level_lte_threshold_and_zero_is_off(): void
    {
        $this->assertTrue(Level::passes(1, 2));
        $this->assertTrue(Level::passes(2, 2));
        $this->assertFalse(Level::passes(3, 2));
        $this->assertFalse(Level::passes(1, 0));
        $this->assertFalse(Level::passes(0, 7), 'OFF is never a sendable event level');
    }

    public function test_signature_matches_an_independent_hmac_computation(): void
    {
        $timestamp = '1790000000';
        $nonce = 'abc123';
        $body = '{"message":"Payment failed"}';
        $secret = 'ils_secret';

        $expected = hash_hmac('sha256', "1790000000\nabc123\n" . '{"message":"Payment failed"}', 'ils_secret');

        $this->assertSame($expected, Signer::sign($timestamp, $nonce, $body, $secret));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Signer::sign($timestamp, $nonce, $body, $secret));
    }

    public function test_nonces_are_random_hex(): void
    {
        $a = Signer::nonce();
        $b = Signer::nonce();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a);
        $this->assertNotSame($a, $b);
    }

    public function test_uuid_v4_format(): void
    {
        $uuid = Uuid::v4();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        $this->assertNotSame($uuid, Uuid::v4());
    }
}
