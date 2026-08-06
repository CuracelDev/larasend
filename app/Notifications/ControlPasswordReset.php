<?php

namespace App\Notifications;

use App\Support\ControlMail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ControlPasswordReset extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct(string $token)
    {
        parent::__construct($token);

        $this->afterCommit();
    }

    public function toMail($notifiable)
    {
        return parent::toMail($notifiable)
            ->mailer(app(ControlMail::class)->mailerOrFail());
    }
}
