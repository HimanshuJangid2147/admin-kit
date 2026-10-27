<?php

namespace AdminKit\Installer;

use RuntimeException;

final class StubInstaller
{
    /** @var array<string,Module> */
    private array $modules = [];

    public function __construct(
        private readonly string $stubsPath,
        private readonly string $basePath,
    ) {
        foreach (glob($stubsPath.'/modules/*/manifest.json') ?: [] as $file) {
            $module = Module::load(dirname($file));
            $this->modules[$module->name] = $module;
        }
    }

    /** @return array<string,Module> */
    public function available(): array
    {
        return $this->modules;
    }

    /** @return string[] */
    public function themes(): array
    {
        return array_map(
            fn (string $file) => basename($file, '.css'),
            glob($this->stubsPath.'/themes/*.css') ?: []
        );
    }

    /**
     * Resolve requested module names plus their dependencies, dependencies first.
     *
     * @param  string[]  $names
     * @return Module[]
     */
    public function resolve(array $names): array
    {
        $ordered = [];
        $visiting = [];

        $visit = function (string $name) use (&$visit, &$ordered, &$visiting): void {
            if (isset($ordered[$name])) {
                return;
            }
            if (isset($visiting[$name])) {
                throw new RuntimeException("Circular module dependency at [{$name}].");
            }

            $module = $this->modules[$name]
                ?? throw new RuntimeException("Unknown module [{$name}]. Available: ".implode(', ', array_keys($this->modules)));

            $visiting[$name] = true;
            foreach ($module->requires() as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$name]);

            $ordered[$name] = $module;
        };

        foreach ($names as $name) {
            $visit($name);
        }

        return array_values($ordered);
    }

    /**
     * Copy one module into the application. Never overwrites existing files unless $force.
     *
     * @return array{copied: string[], skipped: string[], hashes: array<string,string>, migrations: string[], env: string[], providers: array<string,bool>}
     */
    public function install(Module $module, bool $force): array
    {
        $result = [
            'copied' => [],
            'skipped' => [],
            'hashes' => [],
            'migrations' => [],
            'env' => [],
            'providers' => [],
        ];

        foreach ($module->files() as $stub => $destination) {
            $source = $module->path.'/files/'.$stub;
            $target = $this->basePath.'/'.$destination;

            if (! is_file($source)) {
                throw new RuntimeException("Stub missing for module [{$module->name}]: {$stub}");
            }

            $result['hashes'][$destination] = sha1_file($source);

            if (is_file($target) && ! $force) {
                $result['skipped'][] = $destination;

                continue;
            }

            $this->ensureDirectory(dirname($target));
            copy($source, $target);
            $result['copied'][] = $destination;
        }

        $offset = 0;
        foreach ($module->migrations() as $stub => $name) {
            if (glob($this->basePath.'/database/migrations/*_'.$name.'.php')) {
                continue;
            }

            $source = $module->path.'/files/'.$stub;
            $file = 'database/migrations/'.date('Y_m_d_His', time() + $offset++).'_'.$name.'.php';
            $this->ensureDirectory(dirname($this->basePath.'/'.$file));
            copy($source, $this->basePath.'/'.$file);
            $result['migrations'][] = $file;
        }

        $result['env'] = $this->addEnv($module->env());

        foreach ($module->providers() as $provider) {
            $result['providers'][$provider] = $this->registerProvider($provider);
        }

        return $result;
    }

    /** Copy a theme preset to resources/css/admin-theme.css. Returns 'copied' or 'skipped'. */
    public function installTheme(string $theme, bool $force): string
    {
        $source = $this->stubsPath.'/themes/'.$theme.'.css';
        $target = $this->basePath.'/resources/css/admin-theme.css';

        if (! is_file($source)) {
            throw new RuntimeException("Unknown theme [{$theme}]. Available: ".implode(', ', $this->themes()));
        }

        if (is_file($target) && ! $force) {
            return 'skipped';
        }

        $this->ensureDirectory(dirname($target));
        copy($source, $target);

        return 'copied';
    }

    /** Record what was installed so a later diff can show drift between apps. */
    public function writeLock(string $module, array $hashes, ?string $theme): void
    {
        $path = $this->basePath.'/admin-kit.lock.json';
        $lock = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];

        $lock['modules'][$module] = [
            'installed_at' => date('c'),
            'files' => array_merge($lock['modules'][$module]['files'] ?? [], $hashes),
        ];

        if ($theme) {
            $lock['theme'] = $theme;
        }

        file_put_contents($path, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * Compare the lock file against current stubs and application files.
     *
     * @return array{modules: array<string, array<string, array{status: string, destination: string, locked_hash: string, app_hash: ?string, stub_hash: ?string}>>, theme: ?array{name: string, status: string}}
     */
    public function diff(?string $moduleFilter = null): array
    {
        $lockPath = $this->basePath.'/admin-kit.lock.json';
        if (! is_file($lockPath)) {
            throw new RuntimeException('No admin-kit.lock.json found. Run admin:install first.');
        }

        $lock = json_decode(file_get_contents($lockPath), true) ?: [];
        $modules = $lock['modules'] ?? [];
        $result = ['modules' => [], 'theme' => null];

        foreach ($modules as $name => $info) {
            if ($moduleFilter && $name !== $moduleFilter) {
                continue;
            }

            $module = $this->modules[$name] ?? null;
            $reverseFiles = $module ? array_flip($module->files()) : [];
            $filesDiff = [];

            foreach ($info['files'] ?? [] as $dest => $lockedHash) {
                $target = $this->basePath.'/'.$dest;
                $stub = $reverseFiles[$dest] ?? null;
                $source = ($module && $stub) ? $module->path.'/files/'.$stub : null;
                $stubHash = ($source && is_file($source)) ? sha1_file($source) : null;

                if (! is_file($target)) {
                    $filesDiff[$dest] = [
                        'status' => 'missing',
                        'destination' => $dest,
                        'locked_hash' => $lockedHash,
                        'app_hash' => null,
                        'stub_hash' => $stubHash,
                    ];
                } else {
                    $appHash = sha1_file($target);
                    if ($appHash !== $lockedHash) {
                        $filesDiff[$dest] = [
                            'status' => 'locally_modified',
                            'destination' => $dest,
                            'locked_hash' => $lockedHash,
                            'app_hash' => $appHash,
                            'stub_hash' => $stubHash,
                        ];
                    } elseif ($stubHash && $stubHash !== $lockedHash) {
                        $filesDiff[$dest] = [
                            'status' => 'upstream_changed',
                            'destination' => $dest,
                            'locked_hash' => $lockedHash,
                            'app_hash' => $appHash,
                            'stub_hash' => $stubHash,
                        ];
                    } else {
                        $filesDiff[$dest] = [
                            'status' => 'clean',
                            'destination' => $dest,
                            'locked_hash' => $lockedHash,
                            'app_hash' => $appHash,
                            'stub_hash' => $stubHash,
                        ];
                    }
                }
            }

            $result['modules'][$name] = $filesDiff;
        }

        if (isset($lock['theme'])) {
            $themeTarget = $this->basePath.'/resources/css/admin-theme.css';
            $themeSource = $this->stubsPath.'/themes/'.$lock['theme'].'.css';
            $themeStubHash = is_file($themeSource) ? sha1_file($themeSource) : null;

            if (! is_file($themeTarget)) {
                $result['theme'] = [
                    'name' => $lock['theme'],
                    'status' => 'missing',
                ];
            } else {
                $appThemeHash = sha1_file($themeTarget);
                $result['theme'] = [
                    'name' => $lock['theme'],
                    'status' => ($themeStubHash && $appThemeHash !== $themeStubHash) ? 'locally_modified' : 'clean',
                ];
            }
        }

        return $result;
    }

    /** @param array<string,string> $env */
    private function addEnv(array $env): array
    {
        $added = [];

        foreach (['.env', '.env.example'] as $name) {
            $path = $this->basePath.'/'.$name;
            if (! is_file($path) || $env === []) {
                continue;
            }

            $content = file_get_contents($path);
            foreach ($env as $key => $value) {
                if (! preg_match('/^'.preg_quote($key, '/').'=/m', $content)) {
                    $escaped = preg_match('/[\s#\'"=]/', (string) $value)
                        ? '"'.addcslashes((string) $value, '"\\').'"'
                        : $value;
                    $content = rtrim($content, "\n")."\n{$key}={$escaped}\n";
                    $added[$key] = $key;
                }
            }
            file_put_contents($path, $content);
        }

        return array_values($added);
    }

    private function registerProvider(string $class): bool
    {
        $file = $this->basePath.'/bootstrap/providers.php';
        if (! is_file($file)) {
            return false;
        }

        $content = file_get_contents($file);
        $line = $class.'::class';

        if (str_contains($content, $line)) {
            return true;
        }

        $position = strrpos($content, '];');
        if ($position === false) {
            return false;
        }

        file_put_contents($file, substr($content, 0, $position).'    '.$line.",\n".substr($content, $position));

        return true;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
