<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

#[Signature('app:biometric-key {--show : Only print a new key} {--force : Replace a key that is already set}')]
#[Description('Create the encryption key for biometric data (BIOMETRIC_KEY in .env)')]
class BiometricKeyCommand extends Command
{
    public function handle(): int
    {
        $key = 'base64:'.base64_encode(Encrypter::generateKey((string) config('biometrics.cipher')));
        if ($this->option('show')) {
            $this->line($key);

            return self::SUCCESS;
        }

        $file = $this->laravel->environmentFilePath();
        $env = is_file($file) ? (string) file_get_contents($file) : '';
        $current = preg_match('/^BIOMETRIC_KEY=(.*)$/m', $env, $match) === 1 ? trim($match[1]) : '';
        if ($current !== '' && ! $this->option('force')) {
            // A new key makes everything saved with the old one unreadable unless it is kept as a previous key
            $this->components->error('BIOMETRIC_KEY is already set. To change it, add the old key to BIOMETRIC_PREVIOUS_KEYS and run again with --force.');

            return self::FAILURE;
        }

        $line = "BIOMETRIC_KEY={$key}";
        $env = preg_match('/^BIOMETRIC_KEY=.*$/m', $env) === 1
            ? (string) preg_replace('/^BIOMETRIC_KEY=.*$/m', $line, $env)
            : rtrim($env, "\n")."\n{$line}\n";
        file_put_contents($file, $env);
        $this->components->info('Biometric key set in '.basename($file).'.');

        return self::SUCCESS;
    }
}
