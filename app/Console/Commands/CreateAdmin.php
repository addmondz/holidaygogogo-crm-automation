<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdmin extends Command
{
    protected $signature = 'crm:create-admin
        {--name= : Full name}
        {--email= : Login email}
        {--password= : Password (you will be asked if left out)}';

    protected $description = 'Create an admin account (use this once after installing)';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $email = strtolower($this->option('email') ?: text('Email', required: true));
        $password = $this->option('password') ?: password('Password', required: true);

        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Admin,
            'is_available' => false,
        ]);

        $this->components->info("Admin {$email} created. You can now log in.");

        return self::SUCCESS;
    }
}
