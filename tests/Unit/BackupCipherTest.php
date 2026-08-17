<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Backup\BackupCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Encrypting a backup, and refusing to open one that has been damaged.
 *
 * The tests that matter here are the negative ones. A cipher that round-trips
 * is the easy half; what earns its place is that a truncated or altered file
 * fails **loudly**, because the alternative is `psql` executing whatever fell
 * out and a restore that half-works.
 */
class BackupCipherTest extends TestCase
{
    private BackupCipher $cipher;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = base64_decode(BackupCipher::generateKey(), true);
        $this->cipher = new BackupCipher($this->key);
    }

    public function test_what_goes_in_comes_back_out(): void
    {
        $plain = random_bytes(64_000);

        $this->assertSame($plain, $this->roundTrip($plain));
    }

    public function test_a_payload_larger_than_one_chunk_survives(): void
    {
        /*
         * The whole point of streaming. A real dump is gigabytes and the
         * chunking, the tag sequence and the final marker only get exercised
         * once the payload crosses a chunk boundary — so a cipher tested on a
         * short string is untested for the case it exists to handle.
         */
        $plain = random_bytes(BackupCipher::CHUNK * 2 + 12_345);

        $recovered = $this->roundTrip($plain);

        $this->assertSame(strlen($plain), strlen($recovered));
        $this->assertSame($plain, $recovered);
    }

    public function test_the_file_says_what_it_is(): void
    {
        // Somebody will find one of these on an inherited disk with no
        // extension, and the first bytes should answer the question.
        $cipherText = $this->encrypt('hello');

        $this->assertStringStartsWith(BackupCipher::MAGIC, $cipherText);
    }

    public function test_the_plaintext_is_not_in_the_file(): void
    {
        $cipherText = $this->encrypt('NPWP 01.234.567.8-901.000 CV SINAR DISTRIBUSI');

        $this->assertStringNotContainsString('SINAR DISTRIBUSI', $cipherText);
        $this->assertStringNotContainsString('01.234.567', $cipherText);
    }

    public function test_the_wrong_key_is_refused_rather_than_producing_rubbish(): void
    {
        $cipherText = $this->encrypt('anything');
        $other = new BackupCipher(base64_decode(BackupCipher::generateKey(), true));

        /*
         * The failure lands on the first chunk rather than the header, because
         * the header is a nonce and does not depend on the key. That is why
         * the message names both possible causes.
         */
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the key is wrong');

        $this->decryptWith($other, $cipherText);
    }

    public function test_an_altered_byte_is_caught(): void
    {
        /*
         * This is why it is authenticated encryption rather than `openssl
         * enc`. An unauthenticated cipher decrypts a corrupted file into
         * corrupted SQL, and the first anybody knows is a restore that
         * half-executes.
         */
        $cipherText = $this->encrypt(str_repeat('data;', 5_000));

        // Somewhere in the body, past the magic and the header.
        $at = strlen(BackupCipher::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES + 40;
        $cipherText[$at] = $cipherText[$at] === 'x' ? 'y' : 'x';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('altered');

        $this->decrypt($cipherText);
    }

    public function test_a_truncated_file_is_refused_even_though_what_it_has_is_genuine(): void
    {
        /*
         * The nastiest failure this guards against, and the reason the final
         * chunk carries a FINAL tag. A backup cut short by a full disk or a
         * killed process decrypts perfectly right up to where it stops —
         * every byte authentic — and restoring it gives a database missing its
         * last tables with nothing anywhere saying so.
         */
        $cipherText = $this->encrypt(random_bytes(BackupCipher::CHUNK * 2));

        // Cut on a chunk boundary, which is the hard case: every chunk that
        // remains authenticates perfectly. Only the missing end marker says
        // anything is wrong.
        $keep = strlen(BackupCipher::MAGIC)
            + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
            + BackupCipher::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('truncated');

        $this->decrypt(substr($cipherText, 0, $keep));
    }

    public function test_a_file_cut_mid_chunk_is_refused_too(): void
    {
        // Less subtle than the boundary case — the partial chunk fails its own
        // authentication — but it is the shape a failed download actually has.
        $cipherText = $this->encrypt(random_bytes(BackupCipher::CHUNK * 2));

        $this->expectException(RuntimeException::class);

        $this->decrypt(substr($cipherText, 0, (int) (strlen($cipherText) * 0.6)));
    }

    public function test_a_file_that_is_not_ours_is_named_as_such(): void
    {
        // So the person holding it stops suspecting the key.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a backup written by this system');

        $this->decrypt('PK'.str_repeat("\x00", 200));
    }

    public function test_an_empty_backup_is_refused(): void
    {
        // pg_dump producing nothing is a failure that exits zero. Writing the
        // empty result would replace a good backup with a valid empty one.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty backup');

        $this->encrypt('');
    }

    public function test_a_key_of_the_wrong_length_is_refused_at_construction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('32 bytes');

        new BackupCipher('too short');
    }

    public function test_verification_can_read_without_writing_anywhere(): void
    {
        // How a nightly run proves what it just wrote without needing room for
        // a second copy of it.
        $cipherText = $this->encrypt(str_repeat('x', 10_000));

        $in = $this->streamOf($cipherText);

        $this->assertSame(10_000, $this->cipher->decrypt($in, null));
    }

    // --- helpers ------------------------------------------------------------

    private function encrypt(string $plain): string
    {
        $in = $this->streamOf($plain);
        $out = fopen('php://memory', 'r+b');

        $this->cipher->encrypt($in, $out);

        rewind($out);

        return (string) stream_get_contents($out);
    }

    private function decrypt(string $cipherText): string
    {
        return $this->decryptWith($this->cipher, $cipherText);
    }

    private function decryptWith(BackupCipher $cipher, string $cipherText): string
    {
        $in = $this->streamOf($cipherText);
        $out = fopen('php://memory', 'r+b');

        $cipher->decrypt($in, $out);

        rewind($out);

        return (string) stream_get_contents($out);
    }

    private function roundTrip(string $plain): string
    {
        return $this->decrypt($this->encrypt($plain));
    }

    /** @return resource */
    private function streamOf(string $bytes)
    {
        $handle = fopen('php://memory', 'r+b');
        fwrite($handle, $bytes);
        rewind($handle);

        return $handle;
    }
}
