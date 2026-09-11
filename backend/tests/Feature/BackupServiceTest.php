<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
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

    public function test_s3_remote_upload_list_and_signature(): void
    {
        $this->seedLeads();
        BackupService::saveConfig(array_replace(BackupService::defaultConfig(), [
            'remote' => [
                'type' => 's3',
                'url' => '', 'username' => '', 'password' => '', 'path' => 'yimai-backup',
                's3' => [
                    'endpoint' => 'http://minio.local:9000', 'bucket' => 'yimai', 'region' => 'us-east-1',
                    'accessKey' => 'AKIDEXAMPLE', 'secretKey' => 'secret-key-1', 'prefix' => 'yimai-backup/',
                    'style' => 'path',
                ],
            ],
        ]));

        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($request->method() === 'PUT') {
                return Http::response('', 200);
            }
            if ($request->method() === 'GET' && str_contains($url, 'list-type=2')) {
                return Http::response(
                    '<?xml version="1.0"?><ListBucketResult><Contents>'
                    .'<Key>yimai-backup/yimai-backup-20260912-030000-abcd.zip</Key><Size>999</Size>'
                    .'<LastModified>2026-09-12T03:00:00.000Z</LastModified></Contents></ListBucketResult>',
                    200
                );
            }

            return Http::response('', 204);
        });

        $result = BackupService::create('S3测试', uploadRemote: true);
        $this->assertTrue($result['remote_uploaded']);
        $this->assertSame('已上传至 S3 对象存储', $result['remote_note']);
        // path 寻址：PUT 落在 {endpoint}/{bucket}/{prefix}{name}，且带 AWS SigV4 签名头
        Http::assertSent(function (Request $r) use ($result) {
            return $r->method() === 'PUT'
                && str_contains($r->url(), 'http://minio.local:9000/yimai/yimai-backup/'.$result['file'])
                && str_starts_with((string) ($r->header('Authorization')[0] ?? ''), 'AWS4-HMAC-SHA256');
        });

        // 列表解析
        $remote = BackupService::listRemote();
        $this->assertSame('yimai-backup-20260912-030000-abcd.zip', $remote[0]['name']);
        $this->assertSame(999, $remote[0]['size']);

        // 测试连接走 LIST
        $this->assertStringContainsString('连接成功', BackupService::testRemote());
    }

    public function test_webdav_errors_surface_as_readable_messages_not_500(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'username' => 'super-bk', 'name' => '超管备份', 'role' => 'R_SUPER', 'venue' => null,
        ]));

        // 未切换 WebDAV 类型：返回可读提示而非异常/500
        $res = $this->postJson('/api/backup/test-connection', ['remote' => [
            'type' => 'none', 'url' => '', 'username' => '', 'password' => '', 'path' => 'yimai-backup',
        ]]);
        $res->assertOk()->assertJsonPath('code', 1);
        $this->assertStringContainsString('切换为 WebDAV', (string) $res->json('message'));

        // 模拟 NAS 自签名证书失败：翻译成可操作的中文提示
        BackupService::saveConfig(array_replace(BackupService::defaultConfig(), [
            'remote' => ['type' => 'webdav', 'url' => 'https://nas.local:5006', 'username' => 'u', 'password' => 'p', 'path' => 'bk'],
        ]));
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 60: SSL certificate problem: self signed certificate'
            );
        });

        $res2 = $this->postJson('/api/backup/test-connection', ['remote' => [
            'type' => 'webdav', 'url' => 'https://nas.local:5006', 'username' => 'u', 'password' => 'p', 'path' => 'bk',
        ]]);
        $res2->assertOk()->assertJsonPath('code', 1);
        $this->assertStringContainsString('证书', (string) $res2->json('message'));
    }
}
