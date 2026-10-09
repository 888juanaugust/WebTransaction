<?php

declare(strict_types=1);

namespace App\Client\Domain\Ops\Backup;

use RuntimeException;
use SensitiveParameter;

/**
 * Encrypts a backup in authenticated chunks: libsodium's secretstream
 * (XChaCha20-Poly1305), 1 MiB of plaintext per chunk, the last chunk tagged
 * FINAL so a truncated file is refused rather than restored short. A backup
 * is the whole business in one file and leaves the machine, so it is
 * encrypted before it goes and the key never travels with it. Streaming,
 * authenticated, in PHP core: no binary to drift between the machine that
 * wrote the file and the one that must read it.
 */
class BackupCipher
{
    public const CHUNK = 1_048_576;

    /** The first bytes of every artefact: what the file is, before the key is blamed. */
    public const MAGIC = "CENTRALBAK\x01";

    public function __construct(#[SensitiveParameter] private readonly string $key)
    {
        if (strlen($this->key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('The backup key must be exactly '.SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES.' bytes; make one with php artisan central:backup-key.');
        }
    }

    /** A fresh key, base64, for BACKUP_ENCRYPTION_KEY. */
    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    /** From the configured key; refused without one, because a backup written in the clear is the file this exists to avoid. */
    public static function fromConfig(): self
    {
        $encoded = (string) config('ops.backup.encryption_key');
        if ($encoded === '') {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY is not set. Backups are refused without one; run php artisan central:backup-key and keep the key off this server.');
        }
        $key = base64_decode($encoded, true);
        if ($key === false) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY is not valid base64.');
        }

        return new self($key);
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @return int plaintext bytes read
     */
    public function encrypt($in, $out): int
    {
        [$stream, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
        $this->write($out, self::MAGIC);
        $this->write($out, $header);
        $bytes = 0;
        while (! feof($in)) {
            $chunk = fread($in, self::CHUNK);
            if ($chunk === false) {
                throw new RuntimeException('Could not read the plaintext being backed up.');
            }
            if ($chunk === '') {
                continue;
            }
            $bytes += strlen($chunk);
            $tag = feof($in) ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $this->write($out, sodium_crypto_secretstream_xchacha20poly1305_push($stream, $chunk, '', $tag));
        }
        if ($bytes === 0) {
            throw new RuntimeException('Refusing to write an empty backup.');
        }

        return $bytes;
    }

    /**
     * @param  resource  $in
     * @param  resource|null  $out  null verifies without writing
     * @return int plaintext bytes recovered
     */
    public function decrypt($in, $out = null): int
    {
        if ((string) fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new RuntimeException('This is not a backup written by this system: the file header does not match.');
        }
        $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new RuntimeException('The backup file is truncated: it stops inside the header.');
        }
        $stream = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
        $bytes = 0;
        $final = false;
        $cipherChunk = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        while (! feof($in)) {
            $chunk = fread($in, $cipherChunk);
            if ($chunk === false) {
                throw new RuntimeException('Could not read the backup file.');
            }
            if ($chunk === '') {
                continue;
            }
            if ($final) {
                throw new RuntimeException('The backup file has data after its end marker: it has been altered.');
            }
            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($stream, $chunk);
            if ($result === false) {
                throw new RuntimeException('The backup failed authentication: the file has been altered or the key is wrong.');
            }
            [$plain, $tag] = $result;
            $bytes += strlen($plain);
            if ($out !== null) {
                $this->write($out, $plain);
            }
            $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        if (! $final) {
            throw new RuntimeException('The backup file is truncated: it has no end marker. Do not restore from it.');
        }

        return $bytes;
    }

    /** @param  resource  $handle */
    private function write($handle, string $bytes): void
    {
        $written = fwrite($handle, $bytes);
        if ($written === false || $written !== strlen($bytes)) {
            throw new RuntimeException('Short write during the backup: the destination is probably full.');
        }
    }
}
