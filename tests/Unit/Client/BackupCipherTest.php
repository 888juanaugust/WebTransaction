<?php

namespace Tests\Unit\Client;

use App\Client\Domain\Ops\Backup\BackupCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The cipher: a round trip, chunks, and every way a file can be wrong refused. */
class BackupCipherTest extends TestCase
{
    private function cipher(): BackupCipher
    {
        return new BackupCipher(base64_decode(BackupCipher::generateKey()));
    }

    /** @return resource */
    private function stream(string $content)
    {
        $h = fopen('php://memory', 'w+b');
        fwrite($h, $content);
        rewind($h);

        return $h;
    }

    private function encrypt(BackupCipher $cipher, string $plain): string
    {
        $out = fopen('php://memory', 'w+b');
        $cipher->encrypt($this->stream($plain), $out);
        rewind($out);

        return (string) stream_get_contents($out);
    }

    private function decrypt(BackupCipher $cipher, string $encrypted): string
    {
        $out = fopen('php://memory', 'w+b');
        $cipher->decrypt($this->stream($encrypted), $out);
        rewind($out);

        return (string) stream_get_contents($out);
    }

    public function test_a_round_trip_over_several_chunks_comes_back_identical(): void
    {
        $cipher = $this->cipher();
        $plain = random_bytes(BackupCipher::CHUNK * 2 + 12345);
        $encrypted = $this->encrypt($cipher, $plain);

        $this->assertStringStartsWith(BackupCipher::MAGIC, $encrypted);
        $this->assertStringNotContainsString(substr($plain, 100, 64), $encrypted);
        $this->assertSame($plain, $this->decrypt($cipher, $encrypted));
        $this->assertSame(strlen($plain), $cipher->decrypt($this->stream($encrypted), null), 'verifies without writing');
    }

    public function test_a_wrong_key_an_altered_byte_a_truncation_and_trailing_data_are_refused(): void
    {
        $cipher = $this->cipher();
        $encrypted = $this->encrypt($cipher, random_bytes(BackupCipher::CHUNK + 10));

        foreach ([
            [$this->cipher(), $encrypted, 'key is wrong'],
            [$cipher, substr_replace($encrypted, chr(ord($encrypted[200]) ^ 1), 200, 1), 'altered'],
            [$cipher, substr($encrypted, 0, -50), 'altered or the key'],
            [$cipher, substr($encrypted, 0, strlen(BackupCipher::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES + BackupCipher::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES), 'no end marker'],
            [$cipher, substr($encrypted, 0, 20), 'inside the header'],
            [$cipher, $encrypted.'extra', 'after its end marker'],
            [$cipher, 'not ours at all', 'not a backup'],
        ] as [$with, $bytes, $message]) {
            try {
                $with->decrypt($this->stream($bytes), null);
                $this->fail("accepted: {$message}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_an_empty_plaintext_and_a_short_key_are_refused(): void
    {
        try {
            $this->encrypt($this->cipher(), '');
            $this->fail('empty');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        new BackupCipher('short');
    }
}
