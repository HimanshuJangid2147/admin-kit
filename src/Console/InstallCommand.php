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
        {--all : Install all available modules}
        {--theme= : Theme preset name}
        {--list : List available modules and themes}
        {--force : Overwrite files that already exist}
        {--migrate : Run database migrations and seeders automatically}
        {--npm : Run npm install automatically}';

    protected $description = 'Copy the reusable admin base code into this application and configure everything automatically';

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

        if ($this->option('all')) {
            $requested = array_map(fn ($m) => $m->name, $installer->available());
        } elseif (! empty($this->option('modules'))) {
            $requested = array_filter(array_map('trim', explode(',', (string) $this->option('modules'))));
        } elseif ($this->input->isInteractive()) {
            if ($this->confirm('Would you like to install all available modules? (Recommended)', true)) {
                $requested = array_map(fn ($m) => $m->name, $installer->available());
            } else {
                $availableNames = array_keys($installer->available());
                $requested = $this->choice(
                    'Select modules to install (comma-separated)',
                    $availableNames,
                    null,
                    null,
                    true
                );
            }
        } else {
            $requested = [];
        }

        array_unshift($requested, 'core');

        try {
            $modules = $installer->resolve(array_values(array_unique($requested)));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $moduleNames = array_map(fn ($m) => $m->name, $modules);

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
            } catch (RuntimeException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        }

        // Automatic Configuration Steps
        $this->newLine();
        $this->components->info('Automating configuration...');

        $this->automateCssImports();
        $this->automateJsImports($moduleNames);
        $this->automateNpmDependencies(array_unique($npm));
        $this->automateNpmInstall();
        $this->automateSchedule($moduleNames);
        $this->automateComposerAutoload($moduleNames);
        $this->automateUserModelHasRoles();
        $this->automateSpatiePermissions($moduleNames);
        $this->automateDatabaseMigrationsAndSeeding($hasMigrations, $moduleNames);

        $missing = array_values(array_filter(
            array_unique($composer),
            fn (string $package) => ! (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package))
        ));

        $this->newLine();
        if ($missing !== []) {
            $this->components->warn('Missing Composer packages detected:');
            $this->line('  composer require '.implode(' ', $missing));
            $this->newLine();
        }

        if ($notes !== []) {
            $this->components->info('Notes:');
            foreach ($notes as $note) {
                $this->line("  - {$note}");
            }
            $this->newLine();
        }

        $this->components->info('AdminKit setup is complete! Everything is configured and ready to use.');
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Quick Start:</>');
        $this->line('    • URL:      <fg=yellow>/admin</>');
        $this->line('    • Email:    <fg=yellow>admin@example.com</>');
        $this->line('    • Password: <fg=yellow>password</>');
        $this->line('    • Role:     <fg=yellow>super-admin</>');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function automateCssImports(): void
    {
        $appCssPath = base_path('resources/css/app.css');
        if (! file_exists($appCssPath)) {
            return;
        }

        $content = file_get_contents($appCssPath);
        $modified = false;

        if (! str_contains($content, 'admin-theme.css')) {
            $content .= "\n@import \"./admin-theme.css\";\n";
            $modified = true;
        }

        if (! str_contains($content, 'fontawesome-free')) {
            $content .= "@import \"@fortawesome/fontawesome-free/css/all.min.css\";\n";
            $modified = true;
        }

        if ($modified) {
            file_put_contents($appCssPath, $content);
            $this->components->twoColumnDetail('resources/css/app.css', '<fg=green>theme & icons imported</>');
        }
    }

    protected function automateJsImports(array $moduleNames): void
    {
        $appJsPath = base_path('resources/js/app.js');
        if (! file_exists($appJsPath)) {
            return;
        }

        $content = file_get_contents($appJsPath);
        $modified = false;

        $map = [
            'notifications' => "import './notification-drawer';\n",
            'banners' => "import './pageBanner';\n",
            'seo_drawer' => "import './seoCard';\n",
            'document_studio' => "import './documentStudio';\n",
        ];

        foreach ($map as $mod => $importLine) {
            if (in_array($mod, $moduleNames, true)) {
                $checkStr = trim(str_replace(["\n", ';'], '', $importLine));
                if (! str_contains($content, $checkStr)) {
                    $content .= $importLine;
                    $modified = true;
                }
            }
        }

        if ($modified) {
            file_put_contents($appJsPath, $content);
            $this->components->twoColumnDetail('resources/js/app.js', '<fg=green>module scripts imported</>');
        }
    }

    protected function automateNpmDependencies(array $npmPackages): void
    {
        $packageJsonPath = base_path('package.json');
        if (! file_exists($packageJsonPath) || $npmPackages === []) {
            return;
        }

        $json = json_decode(file_get_contents($packageJsonPath), true) ?: [];
        $dependencies = $json['dependencies'] ?? [];

        $knownVersions = [
            '@fortawesome/fontawesome-free' => '^6.7.2',
            'jquery' => '^3.7.1',
            'datatables.net' => '^2.1.8',
            'datatables.net-dt' => '^2.1.8',
            'sweetalert2' => '^11.14.5',
            'pusher-js' => '^8.4.0-rc2',
            '@tiptap/core' => '^2.10.3',
            '@tiptap/starter-kit' => '^2.10.3',
            '@tiptap/extension-table' => '^2.10.3',
            '@tiptap/extension-table-row' => '^2.10.3',
            '@tiptap/extension-table-header' => '^2.10.3',
            '@tiptap/extension-table-cell' => '^2.10.3',
            '@tiptap/extension-bubble-menu' => '^2.10.3',
            'quill' => '^2.0.3',
        ];

        $modified = false;
        foreach ($npmPackages as $pkg) {
            if (! isset($dependencies[$pkg])) {
                $dependencies[$pkg] = $knownVersions[$pkg] ?? '*';
                $modified = true;
            }
        }

        if ($modified) {
            $json['dependencies'] = $dependencies;
            file_put_contents(
                $packageJsonPath,
                json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );
            $this->components->twoColumnDetail('package.json', '<fg=green>npm dependencies configured</>');
        }
    }

    protected function automateSchedule(array $moduleNames): void
    {
        if (! in_array('uploads', $moduleNames, true)) {
            return;
        }

        $consolePath = base_path('routes/console.php');
        if (! file_exists($consolePath)) {
            return;
        }

        $content = file_get_contents($consolePath);
        if (! str_contains($content, 'uploads:clean')) {
            $snippet = "\nuse Illuminate\\Support\\Facades\\Schedule;\n\nSchedule::command('uploads:clean')->daily();\n";
            file_put_contents($consolePath, $content.$snippet);
            $this->components->twoColumnDetail('routes/console.php', '<fg=green>uploads:clean schedule added</>');
        }
    }

    protected function automateNpmInstall(): void
    {
        $packageJsonPath = base_path('package.json');
        if (! file_exists($packageJsonPath)) {
            return;
        }

        $shouldNpm = $this->option('npm') || ($this->input->isInteractive() && $this->confirm('Would you like to run "npm install" now to install front-end packages?', false));

        if (! $shouldNpm) {
            return;
        }

        $npmBinary = $this->findBinary('npm');
        if (! $npmBinary) {
            $this->components->warn('npm binary not found on PATH. Run "npm install" manually when needed.');

            return;
        }

        $this->components->info('Running npm install (this may take a moment)...');
        $process = \Symfony\Component\Process\Process::fromShellCommandline("{$npmBinary} install", base_path());
        $process->setTimeout(300);
        $process->run();

        if ($process->isSuccessful()) {
            $this->components->twoColumnDetail('npm install', '<fg=green>dependencies installed</>');
        } else {
            $this->components->warn('npm install completed with warnings or errors.');
        }
    }

    protected function findBinary(string $binary): ?string
    {
        $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        $output = @shell_exec("{$which} {$binary}");
        if ($output) {
            $path = trim(explode("\n", trim($output))[0]);
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function automateUserModelHasRoles(): void
    {
        $userModelPath = base_path('app/Models/User.php');
        if (! file_exists($userModelPath)) {
            return;
        }

        $content = file_get_contents($userModelPath);
        if (str_contains($content, 'HasRoles')) {
            return;
        }

        if (! str_contains($content, 'use Spatie\Permission\Traits\HasRoles;')) {
            $content = preg_replace(
                '/^(namespace [^;]+;\s*)/m',
                "$1\nuse Spatie\\Permission\\Traits\\HasRoles;\n",
                $content,
                1
            );
        }

        $content = preg_replace(
            '/(class\s+User\s+extends[^{]*\{)/m',
            "$1\n    use HasRoles;",
            $content,
            1
        );

        file_put_contents($userModelPath, $content);
        $this->components->twoColumnDetail('app/Models/User.php', '<fg=green>HasRoles trait added</>');
    }

    protected function automateComposerAutoload(array $moduleNames): void
    {
        if (! in_array('seo_drawer', $moduleNames, true)) {
            return;
        }

        $composerJsonPath = base_path('composer.json');
        if (! file_exists($composerJsonPath)) {
            return;
        }

        $json = json_decode(file_get_contents($composerJsonPath), true) ?: [];
        $files = $json['autoload']['files'] ?? [];

        if (! in_array('app/Helpers/seo.php', $files, true)) {
            $files[] = 'app/Helpers/seo.php';
            $json['autoload']['files'] = $files;
            file_put_contents(
                $composerJsonPath,
                json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );
            $this->components->twoColumnDetail('composer.json', '<fg=green>seo helper autoloaded</>');
        }
    }

    protected function automateSpatiePermissions(array $moduleNames): void
    {
        if (! in_array('rbac', $moduleNames, true)) {
            return;
        }

        if (class_exists('Spatie\\Permission\\PermissionServiceProvider')) {
            $this->callSilent('vendor:publish', [
                '--provider' => 'Spatie\\Permission\\PermissionServiceProvider',
                '--tag' => 'permission-migrations',
            ]);
            $this->callSilent('vendor:publish', [
                '--provider' => 'Spatie\\Permission\\PermissionServiceProvider',
                '--tag' => 'permission-config',
            ]);
            $this->components->twoColumnDetail('Spatie Permissions', '<fg=green>published migrations & config</>');
        }
    }

    protected function automateDatabaseMigrationsAndSeeding(bool $hasMigrations, array $moduleNames): void
    {
        $shouldMigrate = $this->option('migrate') || ($this->input->isInteractive() && $this->confirm('Would you like to run database migrations now?', true));

        if ($shouldMigrate) {
            $this->components->info('Running database migrations...');
            $this->call('migrate', ['--force' => true]);

            if (in_array('rbac', $moduleNames, true) && file_exists(database_path('seeders/AdminPermissionSeeder.php'))) {
                $this->components->info('Seeding admin roles & permissions...');
                $this->call('db:seed', ['--class' => 'AdminPermissionSeeder', '--force' => true]);
            }

            if (in_array('settings', $moduleNames, true) && class_exists(\App\Models\Setting::class) && \Illuminate\Support\Facades\Schema::hasTable('site_settings')) {
                \App\Models\Setting::firstOrCreate(
                    ['id' => 1],
                    [
                        'site_quote' => ['en' => 'Welcome to '.config('app.name', 'Admin Studio')],
                        'address' => 'Corporate Headquarters',
                        'std_code' => '+1',
                        'phone' => '555-0199',
                        'email' => 'admin@example.com',
                        'is_maintenance' => false,
                    ]
                );
                $this->components->twoColumnDetail('Site Settings', '<fg=green>default settings initialized</>');
            }
        }
    }
}
