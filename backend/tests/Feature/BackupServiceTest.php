<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedLeads(): void
    {
        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '备份测试甲', 'phone' => '13900000101',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '新留资',
            'remark' => "带换行与\"引号\"的备注\n第二行",
        ]);
        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '备份测试乙', 'phone' => '13900000102',
            'source' => '美团', 'venue' => '东部店', 'service_teacher' => '', 'status' => '已联系',
        ]);
    }

    public function test_create_backup_and_restore_roundtrip(): void
    {
        $this->seedLeads();
        BackupService::saveConfig(array_replace(BackupService::defaultConfig(), ['keep_env' => false]));

        $result = BackupService::create('测试', uploadRemote: false);
        $this->assertSame('成功', BackupService::config()['status']['last_result'] ?? '');
        $this->assertGreaterThan(0, $result['rows']);
        $this->assertFalse($result['remote_uploaded']);
        $zipPath = BackupService::localPath($result['file']);
        $this->assertFileExists($zipPath);

        // manifest 校验通过
        $manifest = BackupService::verifyZip($zipPath);
        $this->assertSame(2, $manifest['tables']['leads'] ?? 0);
        $this->assertFalse($manifest['include_env']);

        // 破坏当前数据后恢复，数据应回到快照状态
        Lead::where('name', '备份测试甲')->update(['status' => '已流失']);
        $restore = BackupService::restore($zipPath, '测试恢复');
        $this->assertSame('新留资', Lead::where('name', '备份测试甲')->value('status'));
        $this->assertSame(2, $restore['restored']['leads'] ?? 0);
        // 恢复前自动生成安全快照
        $this->assertStringContainsString('恢复前快照', $restore['safety']['label'] ?? '');
        $this->assertTrue(count(BackupService::listLocal()) >= 2);
    }

    public function test_restore_rejects_tampered_zip(): void
    {
        $this->seedLeads();
        BackupService::saveConfig(array_replace(BackupService::defaultConfig(), ['keep_env' => false]));
        $result = BackupService::create('测试', uploadRemote: false);
        $zipPath = BackupService::localPath($result['file']);

        // 篡改包内数据文件（重新打 zip 但不带正确校验和）
        $zip = new \ZipArchive;
        $zip->open($zipPath);
        $dataDir = sys_get_temp_dir().'/tamper-'.uniqid();
        mkdir($dataDir, 0755, true);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                file_put_contents($dataDir.'/'.str_replace('/', '_', $entry), $content);
            }
        }
        $zip->deleteName('data/leads.jsonl');
        file_put_contents($dataDir.'/leads.jsonl', "{\"id\":1}\n{\"id\":2}\n");
        $zip->addFile($dataDir.'/leads.jsonl', 'data/leads.jsonl');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('校验和不匹配');

        try {
            BackupService::restore($zipPath, '篡改恢复');
        } finally {
            @unlink($dataDir.'/leads.jsonl');
            @rmdir($dataDir);
        }
    }

    public function test_webdav_upload_list_and_remote_restore_flow(): void
    {
        $this->seedLeads();
        BackupService::saveConfig(array_replace(BackupService::defaultConfig(), [
            'remote' => [
                'type' => 'webdav', 'url' => 'https://dav.example.com/dav/', 'username' => 'u',
                'password' => 'p', 'path' => 'yimai-backup',
            ],
        ]));

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'dav.example.com/dav/yimai-backup') && $request->method() === 'PROPFIND') {
                $xml = <<<'XML'
                <?xml version="1.0"?>
                <d:multistatus xmlns:d="DAV:">
                  <d:response><d:href>/dav/yimai-backup/</d:href></d:response>
                  <d:response>
                    <d:href>/dav/yimai-backup/yimai-backup-20260912-033000-ab12.zip</d:href>
                    <d:propstat><d:prop>
                      <d:getcontentlength>12345</d:getcontentlength>
                      <d:getlastmodified>Fri, 12 Sep 2026 03:30:00 GMT</d:getlastmodified>
                    </d:prop></d:propstat>
                  </d:response>
                </d:multistatus>
                XML;

                return Http::response($xml, 207);
            }

            return Http::response('', 201);
        });

        $result = BackupService::create('远端测试', uploadRemote: true);
        $this->assertTrue($result['remote_uploaded']);
        Http::assertSent(
            fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/yimai-backup/'.$result['file'])
        );

        $remote = BackupService::listRemote();
        $this->assertSame('yimai-backup-20260912-033000-ab12.zip', $remote[0]['name']);
        $this->assertSame(12345, $remote[0]['size']);
    }

    public function test_local_prune_keeps_latest_n(): void
    {
        BackupService::saveConfig(array_replace(BackupService::defaultConfig(), ['keep_local' => 2]));
        $dir = BackupService::backupDir();
        foreach (['yimai-backup-20260910-000000-aaaa.zip', 'yimai-backup-20260911-000000-bbbb.zip', 'yimai-backup-20260912-000000-cccc.zip'] as $i => $name) {
            file_put_contents($dir.'/'.$name, "fake-{$i}");
        }
        BackupService::pruneLocal();
        $names = array_column(BackupService::listLocal(), 'name');
        $this->assertNotContains('yimai-backup-20260910-000000-aaaa.zip', $names);
        $this->assertCount(2, $names);
        foreach ($names as $n) {
            @unlink($dir.'/'.$n);
        }
    }
}
