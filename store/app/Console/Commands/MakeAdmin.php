<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'store:admin {email} {--name=Admin} {--phone=} {--password=}';

    protected $description = 'Create an admin user, or make an existing user an admin';

    public function handle(): int
    {
        $user = User::firstOrNew(['email' => $this->argument('email')]);
        $user->name = $user->name ?: $this->option('name');
        $user->phone = $this->option('phone') ?: $user->phone;
        if (! $user->exists || $this->option('password')) {
            $user->password = $this->option('password') ?: $this->secret('Password');
        }
        $user->is_admin = true;
        $user->save();
        $this->info("{$user->email} is now an admin. Sign in at /login then open /admin.");

        return self::SUCCESS;
    }
}
