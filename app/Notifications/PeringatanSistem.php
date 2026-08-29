<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Ops\OpsCheck;
use App\Domain\Ops\OpsStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email nobody wants: something on the server is broken right now.
 *
 * Deliberately NOT queued. The queue is one of the things this alert
 * exists to report on, and an alert about a dead worker that waits for a
 * worker to send it is a punchline, not monitoring.
 */
class PeringatanSistem extends Notification
{
    use Queueable;

    /** @param  list<OpsCheck>  $failing */
    public function __construct(private readonly array $failing) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('[GAWAT] Kesehatan sistem '.config('perusahaan.nama_singkat'))
            ->line('Pemeriksaan kesehatan otomatis menemukan masalah:');

        foreach ($this->failing as $check) {
            $label = $check->status === OpsStatus::Gawat ? 'GAWAT' : 'Waspada';
            $mail->line("• {$label} — {$check->judul}: {$check->temuan}");
        }

        return $mail
            ->line('Rincian dari server: php artisan ops:check')
            ->line('Runbook: docs/DEPLOY.md — "When something is wrong".');
    }
}
