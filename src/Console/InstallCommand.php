<?php

namespace AdminKit\Console;

use AdminKit\Installer\StubInstaller;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use RuntimeException;

class InstallCommand extends Command
{
    protected $signature = 'admin:install
        {--modules= : Comma-separated modules to install (core is always included)}
        {--theme= : Theme preset name}
        {--list : List available modules and themes}
        {--force : Overwrite files that already exist}
        {--migrate : Run database migrations after copying files}';

    protected $description = 'Copy the reusable admin base code into this application';

    public function handle(): int
    {
        $installer = new StubInstaller(dirname(__DIR__, 2).'/stubs', base_path());

        if ($this->option('list')) {
            foreach ($installer->available() as $module) {
                $this->components->twoColumnDetail($module->name, $module->description());
            }
            $this->line('Themes: '.implode(', ', $installer->themes()));

            return self::SUCCESS;
        }

        $requested = array_filter(array_map('trim', explode(',', (string) $this->option('modules'))));
        array_unshift($requested, 'core');

        try {
            $modules = $installer->resolve(array_values(array_unique($requested)));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $themes = $installer->themes();
        $theme = $this->option('theme');
        if (! $theme && $themes !== []) {
            $theme = $this->input->isInteractive()
                ? $this->choice('Theme preset', $themes, 0)
                : $themes[0];
        }

        $force = (bool) $this->option('force');
        $composer = [];
        $npm = [];
        $notes = [];
        $hasMigrations = false;

        foreach ($modules as $module) {
            $this->components->info("Module: {$module->name}");

            try {
                $result = $installer->install($module, $force);
            } catch (RuntimeException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            foreach ($result['copied'] as $file) {
                $this->components->twoColumnDetail($file, '<fg=green>copied</>');
            }
            foreach ($result['skipped'] as $file) {
                $this->components->twoColumnDetail($file, '<fg=yellow>skipped (exists, use --force)</>');
            }
            foreach ($result['migrations'] as $file) {
                $this->components->twoColumnDetail($file, '<fg=green>migration added</>');
                $hasMigrations = true;
            }
            foreach ($result['env'] as $key) {
                $this->components->twoColumnDetail($key, '<fg=green>added to .env</>');
            }
            foreach ($result['providers'] as $provider => $registered) {
                if (! $registered) {
                    $notes[] = "Register {$provider} in your providers list (bootstrap/providers.php or config/app.php).";
                }
            }

            $installer->writeLock($module->name, $result['hashes'], null);

            $composer = array_merge($composer, $module->composer());
            $npm = array_merge($npm, $module->npm());
            $notes = array_merge($notes, $module->notes());
        }

        if ($theme) {
            try {
                $status = $installer->installTheme($theme, $force);
                $this->components->twoColumnDetail("resources/css/admin-theme.css ({$theme})", $status);
                $installer->writeLock('core', [], $theme);
                $notes[] = 'Add this line to resources/css/app.css after the tailwindcss import: @import "./admin-theme.css";';
            } catch (RuntimeException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        }

        if ($hasMigrations && ($this->option('migrate') || ($this->input->isInteractive() && $this->confirm('Would you like to run database migrations now?', true)))) {
            $this->newLine();
            $this->components->info('Running database migrations...');
            $this->call('migrate', ['--force' => true]);
        }

        $missing = array_values(array_filter(
            array_unique($composer),
            fn (string $package) => ! (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package))
        ));

        $this->newLine();
        $this->components->info('Next steps');
        if ($missing !== []) {
            $this->line('  composer require '.implode(' ', $missing));
        }
        if ($npm !== []) {
            $this->line('  npm install '.implode(' ', array_unique($npm)));
        }
        if ($hasMigrations) {
            $this->line('  php artisan migrate');
        }
        foreach (array_unique($notes) as $note) {
            $this->line('  - '.$note);
        }

        return self::SUCCESS;
    }
}
