<?php

namespace AdminKit\Console;

use AdminKit\Installer\StubInstaller;
use Illuminate\Console\Command;
use RuntimeException;

class DiffCommand extends Command
{
    protected $signature = 'admin:diff
        {--module= : Compare only a specific installed module}';

    protected $description = 'Compare installed files against current admin-kit stubs';

    public function handle(): int
    {
        $installer = new StubInstaller(dirname(__DIR__, 2).'/stubs', base_path());

        try {
            $diff = $installer->diff($this->option('module'));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $hasChanges = false;

        foreach ($diff['modules'] as $moduleName => $files) {
            $this->components->info("Module: {$moduleName}");

            foreach ($files as $dest => $info) {
                switch ($info['status']) {
                    case 'missing':
                        $hasChanges = true;
                        $this->components->twoColumnDetail($dest, '<fg=red>missing in app</>');
                        break;
                    case 'locally_modified':
                        $hasChanges = true;
                        $this->components->twoColumnDetail($dest, '<fg=yellow>locally modified</>');
                        break;
                    case 'upstream_changed':
                        $hasChanges = true;
                        $this->components->twoColumnDetail($dest, '<fg=cyan>upstream stub updated</>');
                        break;
                    case 'clean':
                        if ($this->output->isVerbose()) {
                            $this->components->twoColumnDetail($dest, '<fg=gray>clean</>');
                        }
                        break;
                }
            }
        }

        if ($diff['theme']) {
            $theme = $diff['theme'];
            $themeFile = 'resources/css/admin-theme.css ('.$theme['name'].')';
            if ($theme['status'] === 'missing') {
                $hasChanges = true;
                $this->components->twoColumnDetail($themeFile, '<fg=red>missing</>');
            } elseif ($theme['status'] === 'locally_modified') {
                $hasChanges = true;
                $this->components->twoColumnDetail($themeFile, '<fg=yellow>locally modified</>');
            } elseif ($this->output->isVerbose()) {
                $this->components->twoColumnDetail($themeFile, '<fg=gray>clean</>');
            }
        }

        if (! $hasChanges) {
            $this->components->info('All installed files are clean and match the locked stubs.');
        }

        return self::SUCCESS;
    }
}
