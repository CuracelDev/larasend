<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\ControlMail;
use App\Support\RegistrationAvailability;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private ControlMail $controlMail) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (! RegistrationAvailability::isOpen()) {
            throw ValidationException::withMessages([
                'email' => 'Registration is closed. Ask a workspace owner to invite you.',
            ]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $isInitialOwner = User::query()->doesntExist();
        $user = new User;
        $user->fill([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
        $user->forceFill($this->controlMail->verificationAttributes($isInitialOwner))->save();

        return $user;
    }
}
