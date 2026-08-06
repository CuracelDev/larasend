<?php

namespace App\Notifications;

use App\Support\ControlMail;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ControlEmailVerification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function toMail($notifiable)
    {
        return parent::toMail($notifiable)
            ->mailer(app(ControlMail::class)->mailerOrFail());
    }
}
