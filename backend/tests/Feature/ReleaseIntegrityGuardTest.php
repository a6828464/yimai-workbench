<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 发布完整性契约：迁移与测试必须随代码一起进版本控制。
 *
 * 起因是一次真实事故形态 —— 代码在版本库里、建列的迁移**不在**（或被 .gitignore 挡了），
 * 于是 `git archive HEAD` 打出来的升级包里没有迁移：
 * 新代码在线上跑、`php artisan migrate` 什么也不做、公开接口直接 500。
 * 这类问题在本地跑测试永远发现不了（本地库早就 migrate 过了），
 * 所以必须在「提交内容」这一层钉住，而不是靠人记得。
 *
 * 这里断言的是 HEAD（或工作区，当尚未提交时）里这些文件的**存在性**，
 * 以及 update.sh 里那道 rsync 之前的迁移门禁确实还在。
 */
class ReleaseIntegrityGuardTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(base_path());
    }

    /** 应用代码引用了 published_shares.enabled 时，HEAD 必须有对应的建列迁移 */
    public function test_enabled_migration_is_version_controlled_when_code_references_it(): void
    {
        $controller = File::get(app_path('Http/Controllers/PublicShareController.php'));
        $this->assertStringContainsString(
            'published_shares',
            $controller,
            '前提断言：控制器确实在读写 published_shares'
        );

        $tracked = $this->trackedMigrationNames();
        $this->assertNotEmpty($tracked, '无法读取 HEAD 的迁移清单（git 不可用？）');

        $hasEnabledMigration = false;
        foreach ($tracked as $name) {
            if (str_contains($name, 'add_enabled_to_published_shares')) {
                $hasEnabledMigration = true;
                break;
            }
        }
        $this->assertTrue(
            $hasEnabledMigration,
            '代码引用了 published_shares.enabled，但 HEAD 的 backend/database/migrations 里没有对应迁移。'
            .'升级包据此打包，缺了它线上会 500。'
        );
    }

    /** 本轮新增的归属/来源标记迁移同样必须在版本控制里 */
    public function test_owner_and_source_migration_is_version_controlled(): void
    {
        $tracked = $this->trackedMigrationNames();
        $found = false;
        foreach ($tracked as $name) {
            if (str_contains($name, 'add_owner_and_source_to_published_shares')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'HEAD 缺少 add_owner_and_source_to_published_shares 迁移');
    }

    /** 本套测试文件自身必须在版本控制里（否则 CI 里跑了什么、本地跑了什么无法对齐） */
    public function test_share_tests_are_version_controlled(): void
    {
        $tracked = $this->runGit(['ls-files', 'backend/tests']);
        if ($tracked === null) {
            $this->markTestSkipped('git 不可用，跳过版本控制断言');
        }

        $this->assertStringContainsString(
            'backend/tests/Feature/PublicShareSalesTest.php',
            $tracked,
            'PublicShareSalesTest.php 未纳入版本控制：CI 不会跑它，回归无人拦'
        );
    }

    /**
     * CI 守卫：应用代码引用 published_shares.enabled 时，
     * backend/database/migrations 与 backend/tests 下不得存在未跟踪文件。
     *
     * 未跟踪 = 不会进 git archive = 不会进升级包。本地全绿、线上 500 就出在这里。
     */
    public function test_no_untracked_files_under_migrations_or_tests(): void
    {
        $untracked = $this->runGit(['ls-files', '--others', '--exclude-standard', 'backend/database/migrations', 'backend/tests']);
        if ($untracked === null) {
            $this->markTestSkipped('git 不可用，跳过未跟踪文件断言');
        }

        $this->assertSame(
            '',
            trim($untracked),
            "backend/database/migrations 或 backend/tests 下存在未跟踪文件，这些文件不会进升级包：\n".$untracked
        );
    }

    /** update.sh 必须在 rsync 之前做迁移完整性门禁 */
    public function test_update_sh_guards_migrations_before_rsync(): void
    {
        $script = File::get($this->repoRoot().'/update.sh');

        $guardPos = strpos($script, '迁移必须随包到达');
        $rsyncPos = strpos($script, 'rsync -a \\');
        $this->assertNotFalse($guardPos, 'update.sh 缺少「迁移必须随包到达」门禁');
        $this->assertNotFalse($rsyncPos, 'update.sh 缺少 rsync 覆盖步骤');
        $this->assertLessThan(
            $rsyncPos,
            $guardPos,
            '迁移门禁必须在 rsync 覆盖代码之前执行，否则代码已换、迁移没跑，站点半新半旧'
        );

        $this->assertStringContainsString('add_enabled_to_published_shares', $script);
        $this->assertStringContainsString('add_owner_and_source_to_published_shares', $script);
    }

    /** CI 工作流须在打包前跑后端测试（迁移/测试没入库时会被上面的用例拦下） */
    public function test_ci_runs_backend_tests_before_assembling_package(): void
    {
        $root = $this->repoRoot();
        $ci = File::get($root.'/.github/workflows/release-latest.yml');

        $this->assertStringContainsString('php artisan test', $ci, 'CI 必须在打包前跑后端测试');
        $this->assertStringContainsString('release:', $ci);

        $testPos = strpos($ci, 'php artisan test');
        $assemblePos = strpos($ci, 'Assemble release package');
        $this->assertNotFalse($assemblePos);
        $this->assertLessThan(
            $assemblePos,
            $testPos,
            '后端测试必须在打包之前执行'
        );
    }

    /** @return string[] HEAD 里的迁移文件名 */
    private function trackedMigrationNames(): array
    {
        $out = $this->runGit(['ls-tree', '-r', 'HEAD', '--name-only', 'backend/database/migrations']);
        if ($out !== null && trim($out) !== '') {
            return array_values(array_filter(array_map('basename', preg_split('/\s+/', trim($out)))));
        }

        // git 不可用（或仓库未初始化）时退回工作区扫描，仍然能拦住「文件根本没建」
        return array_map('basename', File::glob(database_path('migrations').'/*.php'));
    }

    /**
     * 跑 git 并返回 stdout；git 不可用（命令缺失/非零退出）时返回 null 表示「无法判定」。
     *
     * 刻意用 exec() 而不是 shell_exec()：shell_exec 在命令**输出为空**时也返回 null，
     * 于是「本应断言空输出」的用例会伪装成 skipped 静默通过 ——
     * 守卫测试最不该有的行为就是「什么都没检查却显示通过」。
     */
    private function runGit(array $args): ?string
    {
        $cmd = 'git -C '.escapeshellarg($this->repoRoot()).' '
            .implode(' ', array_map('escapeshellarg', $args)).' 2>/dev/null';
        $lines = [];
        $code = 1;
        exec($cmd, $lines, $code);
        if ($code !== 0) {
            return null;
        }

        return implode("\n", $lines);
    }
}
