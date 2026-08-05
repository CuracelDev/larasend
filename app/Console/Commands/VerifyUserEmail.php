<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('larasend:verify-user {email : Exact user email address} {--force : Confirm the administrative recovery action}')]
#[Description('Administratively verify a Larasend user when control email is unavailable')]
class VerifyUserEmail extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('The --force option is required for this administrative recovery action.');

            return self::INVALID;
        }

        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user exists with email address {$email}.");

            return self::FAILURE;
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'email_verification_required_at' => null,
        ])->save();

        $this->info("Verified {$user->email}. The account can sign in without a control-email message.");

        return self::SUCCESS;
    }
}
