<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Command
{
    protected $signature = 'johnny:admin {email} {password}';

    protected $description = 'Create or update a panel admin user (for first login).';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->argument('password');

        $user = User::query()->where('email', $email)->first();
        if ($user) {
            $user->forceFill([
                'password' => Hash::make($password),
                'name' => $user->name ?? 'Admin',
            ])->save();
            $this->info('User updated.');
        } else {
            User::query()->create([
                'name' => 'Admin',
                'email' => $email,
                'password' => Hash::make($password),
            ]);
            $this->info('User created.');
        }

        return self::SUCCESS;
    }
}
