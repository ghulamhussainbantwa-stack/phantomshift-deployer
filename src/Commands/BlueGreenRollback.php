<?php

namespace Phantomshift\LaravelDeployer\Commands;

use Illuminate\Console\Command;

class BlueGreenRollback extends Command
{
    protected $signature = 'deploy:rollback
                            {--force : Bina confirm ke rollback karo}
                            {--list  : Available releases dikhao}';

    protected $description = 'Previous version pe rollback karo — zero downtime';

    private string $releasesDir = '/var/www/releases';
    private string $currentLink = '/var/www/current';
    private string $nginxConf   = '/etc/nginx/sites-available/myapp';
    private string $activePool  = '/var/run/active-pool';
    private string $historyFile = '/var/run/deploy-history';
    private array  $pools       = [
        'blue'  => '/run/php-fpm-blue.sock',
        'green' => '/run/php-fpm-green.sock',
    ];

    public function handle(): int
    {
        $this->printBanner();

        if ($this->option('list')) {
            return $this->showReleases();
        }

        $history = $this->getHistory();

        if (count($history) < 2) {
            $this->error('Rollback possible nahi — sirf ek release available hai.');
            return self::FAILURE;
        }

        $currentRelease  = $history[0];
        $previousRelease = $history[1];
        $activePool      = $this->getActivePool();
        $rollbackPool    = $activePool === 'green' ? 'blue' : 'green';
        $rollbackSock    = $this->pools[$rollbackPool];
        $oldSock         = $this->pools[$activePool];

        $this->newLine();
        $this->line("<fg=yellow>Current release  : {$currentRelease}</>");
        $this->line("<fg=green>Rollback target  : {$previousRelease}</>");
        $this->line("<fg=cyan>Rollback pool    : {$rollbackPool}</>");
        $this->newLine();

        if (!$this->option('force')) {
            if (!$this->confirm("Rollback to {$previousRelease}?")) {
                $this->warn('Rollback cancelled.');
                return self::FAILURE;
            }
        }

        $this->step('Verifying previous release...');
        if (!is_dir("{$this->releasesDir}/{$previousRelease}")) {
            $this->error("Release not found: {$previousRelease}");
            return self::FAILURE;
        }
        $this->info('Release found');

        $this->step("Restoring {$previousRelease} to {$rollbackPool} pool...");
        shell_exec("cp -r {$this->releasesDir}/{$previousRelease} /var/www/{$rollbackPool}");

        $this->step('Rolling back migration...');
        shell_exec("cd /var/www/{$rollbackPool} && php artisan migrate:rollback --force");

        $this->step('Rebuilding cache...');
        shell_exec("cd /var/www/{$rollbackPool} && php artisan config:cache");
        shell_exec("cd /var/www/{$rollbackPool} && php artisan route:cache");
        shell_exec("cd /var/www/{$rollbackPool} && php artisan view:cache");

        $this->step("Switching Nginx to {$rollbackPool}...");
        $this->switchNginx($rollbackSock);

        file_put_contents($this->activePool, $rollbackPool);

        $this->step("Draining current pool ({$activePool})...");
        $this->drainPool($oldSock);

        $this->step('Updating symlink...');
        shell_exec("ln -sfn /var/www/{$rollbackPool} {$this->currentLink}");

        $this->removeFromHistory($currentRelease);

        $this->newLine();
        $this->printSuccess($rollbackPool, $previousRelease, $currentRelease);

        return self::SUCCESS;
    }

    private function getHistory(): array
    {
        if (!file_exists($this->historyFile)) {
            return [];
        }
        $lines = file($this->historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_reverse($lines);
    }

    private function removeFromHistory(string $release): void
    {
        $history = $this->getHistory();
        $updated = array_filter($history, fn($r) => $r !== $release);
        file_put_contents(
            $this->historyFile,
            implode("\n", array_reverse(array_values($updated))) . "\n"
        );
    }

    private function showReleases(): int
    {
        $history = $this->getHistory();

        if (empty($history)) {
            $this->warn('Koi release history nahi mili.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Available Releases:</>');
        $this->line(str_repeat('─', 50));

        foreach ($history as $index => $release) {
            $label = $index === 0 ? ' <fg=green>[CURRENT]</>' : '';
            $arrow = $index === 1 ? ' <fg=yellow>← rollback target</>' : '';
            $this->line("  {$release}{$label}{$arrow}");
        }

        $this->line(str_repeat('─', 50));
        $this->newLine();

        return self::SUCCESS;
    }

    private function getActivePool(): string
    {
        if (file_exists($this->activePool)) {
            return trim(file_get_contents($this->activePool));
        }
        return 'green';
    }

    private function switchNginx(string $newSock): void
    {
        $conf    = file_get_contents($this->nginxConf);
        $updated = preg_replace(
            '/default\s+"unix:[^"]*"/',
            "default \"unix:{$newSock}\"",
            $conf
        );
        file_put_contents($this->nginxConf, $updated);
        shell_exec('nginx -s rel