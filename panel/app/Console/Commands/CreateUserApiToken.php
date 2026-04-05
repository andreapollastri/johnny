<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateUserApiToken extends Command
{
    protected $signature = 'johnny:api-token {email : Panel user email} {--name=api : Token name (shown in the database)}';

    protected $description = 'Issue a Sanctum personal access token (same as Settings → Panel API tokens; shown once).';

    public function handle(): int
    {
        $email = $this->argument('email');
        $name = (string) $this->option('name');

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        $token = $user->createToken($name)->plainTextToken;

        $this->line($token);
        $this->newLine();
        $this->warn('Store this token securely; it will not be shown again.');

        return self::SUCCESS;
    }
}
