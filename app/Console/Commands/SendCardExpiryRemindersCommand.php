<?php

namespace App\Console\Commands;

use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Services\Cards\CardExpiryReminderService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('app:send-card-expiry-reminders {tenant? : Organization slug; all organizations when left out}')]
#[Description('Send the reminders due today before cards\' last days, once each, as each organization set them')]
class SendCardExpiryRemindersCommand extends Command
{
    public function handle(CardExpiryReminderService $reminders, TenantRepositoryInterface $tenants): int
    {
        $slug = $this->argument('tenant');
        $tenant = is_string($slug) ? $tenants->findBySlug($slug) : null;
        if (is_string($slug) && $tenant === null) {
            $this->components->error('No such organization.');

            return self::FAILURE;
        }

        $sent = $reminders->send($tenant);
        foreach ($sent as $organization => $count) {
            $this->components->info("{$organization}: {$count} ".Str::plural('reminder', $count).' sent.');
        }
        if ($sent === []) {
            $this->components->info('No organization sends card expiry reminders.');
        }

        return self::SUCCESS;
    }
}
