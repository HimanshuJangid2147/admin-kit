<?php

namespace AdminKit\Tests;

use AdminKit\Installer\Module;
use AdminKit\Installer\StubInstaller;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StubInstallerTest extends TestCase
{
    private string $tempDir;

    private string $stubsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/admin_kit_test_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir.'/bootstrap', 0755, true);
        file_put_contents($this->tempDir.'/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");
        file_put_contents($this->tempDir.'/.env', "APP_NAME=Laravel\n");
        file_put_contents($this->tempDir.'/.env.example', "APP_NAME=Laravel\n");

        $this->stubsDir = dirname(__DIR__).'/stubs';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_loads_available_modules(): void
    {
        $installer = new StubInstaller($this->stubsDir, $this->tempDir);
        $available = $installer->available();

        $this->assertArrayHasKey('core', $available);
        $this->assertArrayHasKey('rbac', $available);
    }

    public function test_resolves_module_dependencies_in_order(): void
    {
        $installer = new StubInstaller($this->stubsDir, $this->tempDir);
        $resolved = $installer->resolve(['rbac']);

        $names = array_map(fn (Module $m) => $m->name, $resolved);
        $this->assertSame(['core', 'rbac'], $names);
    }

    public function test_throws_on_unknown_module(): void
    {
        $installer = new StubInstaller($this->stubsDir, $this->tempDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown module [non_existent]');
        $installer->resolve(['non_existent']);
    }

    public function test_installs_module_and_creates_lock_file(): void
    {
        $installer = new StubInstaller($this->stubsDir, $this->tempDir);
        $module = $installer->available()['core'];

        $result = $installer->install($module, force: false);
        $installer->writeLock($module->name, $result['hashes'], 'emerald-orange');

        $this->assertFileExists($this->tempDir.'/config/admin.php');
        $this->assertFileExists($this->tempDir.'/admin-kit.lock.json');

        $lock = json_decode(file_get_contents($this->tempDir.'/admin-kit.lock.json'), true);
        $this->assertArrayHasKey('core', $lock['modules']);
        $this->assertSame('emerald-orange', $lock['theme']);
    }

    public function test_second_install_skips_existing_files_without_force(): void
    {
        $installer = new StubInstaller($this->stubsDir, $this->tempDir);
        $module = $installer->available()['core'];

        $first = $installer->install($module, force: false);
        $this->assertNotEmpty($first['copied']);
        $this->assertEmpty($first['skipped']);

        $second = $installer->install($module, force: false);
        $this->assertEmpty($second['copied']);
        $this->assertNotEmpty($second['skipped']);
    }

    public function test_force_overwrites_existing_files(): void
    {
        $installer = new StubInstaller($this->stubsDir, $this->tempDir);
        $module = $installer->available()['core'];

        $installer->install($module, force: false);
        file_put_contents($this->tempDir.'/config/admin.php', 'MODIFIED');

        $forced = $installer->install($module, force: true);
        $this->assertContains('config/admin.php', $forced['copied']);
        $this->assertNotSame('MODIFIED', file_get_contents($this->tempDir.'/config/admin.php'));
    }

    public function test_diff_detects_clean_and_modified_files(): void
    {
        $installer = new StubInstaller($this->stubsDir, $this->tempDir);
        $module = $installer->available()['core'];

        $result = $installer->install($module, force: false);
        $installer->writeLock($module->name, $result['hashes'], null);

        $diff = $installer->diff();
        $this->assertSame('clean', $diff['modules']['core']['config/admin.php']['status']);

        file_put_contents($this->tempDir.'/config/admin.php', 'CUSTOMIZED');
        $diffAfter = $installer->diff();
        $this->assertSame('locally_modified', $diffAfter['modules']['core']['config/admin.php']['status']);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
