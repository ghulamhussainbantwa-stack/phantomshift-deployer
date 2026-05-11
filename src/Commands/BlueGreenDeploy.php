<?php

namespace Phantomshift\LaravelDeployer\Commands;

use Illuminate\Console\Command;

class BlueGreenDeploy extends Command
{
    protected $signature = 'deploy:blue-green
                            {release : Release version ya folder name}
                            {--force : Bina confirm ke deploy karo}
                            {--max-drain=30 : Maximum drain wait seconds}';

    protected $description = 'Zero downtime Blue-Green deployment for Laravel';

    private string $releasesDir = '/var/www/releases';
    private string $currentLink = '/var/www/current';
    private string $nginxConf   = '/etc/nginx/sites-available/myapp';
    private string $activePool  = '/var/run/active-pool';
    private array  $pools       = [
        'blue'  => '/run/php-fpm-blue.sock',
        'green' => '/run/php-fpm-green.sock',
    ];

    public function handle(): int
    {
        $release    = $this->argument('release');
        $activePool = $this->getActivePool();
        $newPool    = $activePool === 'green' ? 'blue' : 'green';
        $newSock    = $this->pools[$newPool];
        $oldSock    = $this->pools[$activePool];

        $this->printBanner();

        $this->info("Active pool  : <fg=yellow>{$activePool}</>");
        $this->info("Deploying to : <fg=green>{$newPool}</>");
        $this->newLine();

        if (!$this->option('force')) {
            if (!$this->confirm("Deploy <fg=green>{$release}</> to <fg=green>{$newPool}</> pool?")) {
                $this->warn('Deploy cancelled.');
                return self::FAILURE;
            }
        }

        $this->step('Copying release files...');
        $this->runCommand("cp -r {$this->releasesDir}/{$release} /var/www/{$newPool}");

        $this->step('Installing dependencies...');
        $this->runCommand("cd /var/www/{$newPool} && composer install --no-dev --optimize-autoloader");

        $this->step('Running migrations...');
        $this->runCommand("cd /var/www/{$newPool} && php artisan migrate --force");

        $this->step('Caching config, routes, views...');
        $this->runCommand("cd /var/www/{$newPool} && php artisan config:cache");
        $this->runCommand("cd /var/www/{$newPool} && php artisan route:cache");
        $this->runCommand("cd /var/www/{$newPool} && php artisan view:cache");

        $this->step("Switching Nginx to {$newPool} pool...");
        $this->switchNginx($newSock);

        file_put_contents($this->activePool, $newPool);

        $this->step("Draining old pool ({$activePool})...");
        $drained = $this->drainPool($oldSock);

        if (!$drained) {
            $this->warn('Force drained — kuch requests drop hui hain');
        } else {
            $this->info('Clean drain — koi request drop nahi hui');
        }

        $this->step('Updating symlink...');
        $this->runCommand("ln -sfn /var/www/{$newPool} {$this->currentLink}");

        $this->newLine();
        $this->printSuccess($newPool, $release);

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
        $this->runCommand('nginx -s reload');
    }

    private function drainPool(string $sock): bool
    {
        $maxWait = (int) $this->option('max-drain');
        $waited  = 0;
        $bar     = $this->output->createProgressBar($maxWait);
        $bar->start();

        while ($waited < $maxWait) {
            $connections = (int) shell_exec(
                "ss -x | grep '{$sock}' | grep -c 'ESTAB'"
            );

            if ($connections === 0) {
                $bar->finish();
                $this->newLine();
                return true;
            }

            $bar->advance();
            sleep(1);
            $waited++;
        }

        $bar->finish();
        $this->newLine();
        return false;
    }

    private function runCommand(string $cmd): void
    {
        shell_exec($cmd);
    }

    private function step(string $message): void
    {
        $this->line("<fg=cyan>>>> {$message}</>");
    }

    private function printBanner(): void
    {
        $this->newLine();
        $this->line('<fg=green>
    ██████╗ ██╗  ██╗ █████╗ ███╗  ██╗████████╗ ██████╗ ███╗  ███╗
    ██╔══██╗██║  ██║██╔══██╗████╗ ██║╚══██╔══╝██╔═══██╗████╗████║
    ██████╔╝███████║███████║██╔██╗██║   ██║   ██║   ██║██╔████╔██║
    ██╔═══╝ ██╔══██║██╔══██║██║╚████║   ██║   ██║   ██║██║╚██╔╝██║
    ██║     ██║  ██║██║  ██║██║ ╚███║   ██║   ╚██████╔╝██║ ╚═╝ ██║
    ╚═╝     ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚══╝  ╚═╝    ╚═════╝ ╚═╝     ╚═╝
    PhantomShift — Zero Downtime Deployer
        </>');
        $this->newLine();
    }

    private function printSuccess(string $pool, string $release): void
    {
        $this->line('<fg=green>
    ╔══════════════════════════════════════╗
    ║   ✓ DEPLOYMENT SUCCESSFUL            ║
    ║                                      ║
    ║   Pool    : ' . str_pad($pool, 27) . '║
    ║   Release : ' . str_pad($release, 27) . '║
    ╚══════════════════════════════════════╝
        </>');
    }
}