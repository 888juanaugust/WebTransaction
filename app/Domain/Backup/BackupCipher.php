<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use RuntimeException;
use SensitiveParameter;

/**
 * Encrypting a backup, in chunks, with the tampering caught.
 *
 * A backup of this database is the whole business in one file: every
 * customer's NPWP and address, every price we charge, every margin. It leaves
 * the machine and lands somewhere we do not control, so it is encrypted before
 * it goes and the key never travels with it.
 *
 * **libsodium's secretstream, not `openssl enc`.** Three reasons, and the
 * third is the one that matters:
 *
 *  - It is authenticated. A file that was altered in storage fails to decrypt
 *    rather than producing plausible rubbish that `psql` then executes.
 *  - It is streaming. A dump is gigabytes; loading it into a PHP string to
 *    encrypt in one shot is how a backup starts failing silently the month the
 *    business grows.
 *  - It is in PHP core. No binary to install, no version drift between the
 *    machine that wrote the backup and the machine that has to read it at
 *    three in the morning. That second machine is often not the first one.
 *
 * The chunk size is a trade: bigger means fewer authentication tags and
 * slightly smaller files, smaller means less memory. 1 MiB is far below any
 * sane `memory_limit` and far above the syscall overhead.
 */
class BackupCipher
{
    /** Bytes of plaintext per authenticated chunk. */
    public const CHUNK = 1_048_576;

    /**
     * Written at the front of every artefact.
     *
     * Not decoration. Somebody will one day find one of these files with no
     * extension on a disk they inherited, and the first bytes should say what
     * it is. It also lets `verify` reject a file that is not ours before
     * blaming the key.
     */
    public const MAGIC = "JIIBAK\x01";

    public function __construct(
        #[SensitiveParameter] private readonly string $key,
    ) {
        if (strlen($this->key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException(
                'Backup key must be exactly '
                .SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES
                .' bytes. Generate one with `php artisan backup:key`.'
            );
        }
    }

    /** A fresh key, base64 encoded, for BACKUP_ENCRYPTION_KEY. */
    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    /**
     * Build from the configured key, failing clearly when there isn't one.
     *
     * Refusing to run without a key is deliberate. The alternative — writing
     * the backup in the clear and warning — produces exactly the file we were
     * trying not to produce, and the warning scrolls past.
     */
    public static function fromConfig(): self
    {
        $encoded = (string) config('backup.encryption_key');

        if ($encoded === '') {
            throw new RuntimeException(
                'BACKUP_ENCRYPTION_KEY is not set. Backups are refused without one — '
                .'run `php artisan backup:key` and read docs/BACKUP.md before storing it.'
            );
        }

        $key = base64_decode($encoded, true);

        if ($key === false) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY is not valid base64.');
        }

        return new self($key);
    }

    /**
     * Encrypt one stream into another.
     *
     * @param  resource  $in
     * @param  resource  $out
     * @return int plaintext bytes read
     */
    public function encrypt($in, $out): int
    {
        [$stream, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);

        $this->write($out, self::MAGIC);
        $this->write($out, $header);

        $plaintextBytes = 0;

        while (! feof($in)) {
            $chunk = fread($in, self::CHUNK);

            if ($chunk === false) {
                throw new RuntimeException('Could not read the plaintext being backed up.');
            }

            if ($chunk === '') {
                continue;
            }

            $plaintextBytes += strlen($chunk);

            /*
             * The final chunk is tagged FINAL. That tag is what makes a
             * truncated file detectable: a backup cut short by a full disk
             * decrypts happily right up to the point it stops, and without
             * this we would restore a database missing its last tables and
             * never know.
             */
            $tag = feof($in)
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

            $this->write($out, sodium_crypto_secretstream_xchacha20poly1305_push($stream, $chunk, '', $tag));
        }

        if ($plaintextBytes === 0) {
            throw new RuntimeException('Refusing to write an empty backup.');
        }

        return $plaintextBytes;
    }

    /**
     * Decrypt one stream into another.
     *
     * @param  resource  $in
     * @param  resource|null  $out  null to verify without writing anywhere
     * @return int plaintext bytes recovered
     */
    public function decrypt($in, $out = null): int
    {
        $magic = (string) fread($in, strlen(self::MAGIC));

        if ($magic !== self::MAGIC) {
            throw new RuntimeException(
                'This is not a backup written by this system — the file header does not match.'
            );
        }

        $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

        if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new RuntimeException('Backup file is truncated: it stops inside the header.');
        }

        /*
         * The header is a nonce, not a check on the key — a wrong key gets
         * through here and fails on the first chunk instead, which is why the
         * authentication message below names the key as the likely cause.
         * There is deliberately no branch on this call's result: it throws on
         * a malformed header, and the length check above has already covered
         * a short one.
         */
        $stream = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);

        $plaintextBytes = 0;
        $sawFinal = false;

        // Ciphertext chunks carry an authentication tag, so they are longer
        // than the plaintext that went in.
        $cipherChunk = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

        while (! feof($in)) {
            $chunk = fread($in, $cipherChunk);

            if ($chunk === false) {
                throw new RuntimeException('Could not read the backup file.');
            }

            if ($chunk === '') {
                continue;
            }

            if ($sawFinal) {
                throw new RuntimeException('Backup file has data after its end marker — it has been altered.');
            }

            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($stream, $chunk);

            if ($result === false) {
                throw new RuntimeException(
                    'Backup failed authentication. The file has been altered or the key is wrong.'
                );
            }

            [$plain, $tag] = $result;

            $plaintextBytes += strlen($plain);

            if ($out !== null) {
                $this->write($out, $plain);
            }

            $sawFinal = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }

        /*
         * No FINAL tag means the file ends early — a full disk, a killed
         * process, an interrupted download. Every byte we decrypted was
         * genuine, which is exactly why this has to be an error: the data
         * looks perfect and is incomplete.
         */
        if (! $sawFinal) {
            throw new RuntimeException(
                'Backup file is truncated: it has no end marker. Do not restore from it.'
            );
        }

        return $plaintextBytes;
    }

    /** @param  resource  $handle */
    private function write($handle, string $bytes): void
    {
        $written = fwrite($handle, $bytes);

        if ($written === false || $written !== strlen($bytes)) {
            throw new RuntimeException('Short write during backup. The destination is probably full.');
        }
    }
}
