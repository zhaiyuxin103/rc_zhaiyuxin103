<?php

namespace App\Console\Commands;

use App\Enums\OutboundAbility;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('outbounds:issue-token
    {email : The service account email address}
    {--name=outbound-client : The Sanctum token name}
    {--create-user : Create the service account when it does not exist}
    {--user-name= : The display name used when creating the service account}
    {--ability=* : A token ability; repeat this option for multiple abilities}
')]
#[Description('Issue a Sanctum token for an outbound service account.')]
class IssueOutboundToken extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('The email address is invalid.');

            return self::FAILURE;
        }

        $tokenName = trim((string) $this->option('name'));

        if ($tokenName === '' || mb_strlen($tokenName) > 255) {
            $this->error('The token name must contain between 1 and 255 characters.');

            return self::FAILURE;
        }

        $abilities = $this->resolveAbilities();

        if ($abilities === null) {
            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null && ! $this->option('create-user')) {
            $this->error("No user exists for {$email}. Use --create-user to provision a service account.");

            return self::FAILURE;
        }

        if ($user === null) {
            $user = User::query()->create([
                'name' => trim((string) $this->option('user-name')) ?: Str::before($email, '@'),
                'email' => $email,
                'password' => Str::random(64),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->info("Created service account {$email}.");
        }

        $token = $user->createToken($tokenName, $abilities);

        $this->info("Issued outbound token for {$email}. Store it securely; it will not be shown again.");
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }

    /**
     * Resolve and validate the requested token abilities.
     *
     * @return list<string>|null
     */
    private function resolveAbilities(): ?array
    {
        $requestedAbilities = array_values(array_filter(
            array_map(
                fn (mixed $ability): string => trim((string) $ability),
                (array) $this->option('ability'),
            ),
            fn (string $ability): bool => $ability !== '',
        ));
        $allowedAbilities = array_map(
            fn (OutboundAbility $ability): string => $ability->value,
            OutboundAbility::cases(),
        );

        if ($requestedAbilities === []) {
            return $allowedAbilities;
        }

        $invalidAbilities = array_values(array_diff($requestedAbilities, $allowedAbilities));

        if ($invalidAbilities !== []) {
            $this->error('Unsupported ability: '.implode(', ', $invalidAbilities));
            $this->line('Allowed abilities: '.implode(', ', $allowedAbilities));

            return null;
        }

        return array_values(array_unique($requestedAbilities));
    }
}
