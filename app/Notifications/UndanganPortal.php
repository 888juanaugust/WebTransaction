<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your portal account exists — choose your password."
 *
 * The invitation is a password-reset link wearing different words. That is
 * deliberate: the buyer sets their own password on the same signed page,
 * against the same single-use token table, with the same expiry — and no
 * password ever travels by email or sits in a staff member's chat history.
 */
class UndanganPortal extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly string $namaPerusahaan,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Akun portal pelanggan '.config('perusahaan.nama_singkat'))
            ->greeting('Halo '.$notifiable->name.',')
            ->line("Akun portal pelanggan untuk **{$this->namaPerusahaan}** sudah dibuat. "
                .'Silakan atur kata sandi Anda untuk mulai memesan.')
            ->action('Atur kata sandi', $this->url)
            ->line('Tautan ini berlaku 60 menit dan hanya bisa dipakai sekali. Bila '
                .'kedaluwarsa, gunakan "Lupa kata sandi?" di halaman masuk portal — '
                .'tautan baru akan dikirim ke email ini.')
            ->salutation('Hormat kami, '.config('perusahaan.nama_singkat'));
    }
}
