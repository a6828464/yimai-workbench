# -*- coding: utf-8 -*-
import hashlib, base64, subprocess, sys

src = open('backend/routes/api.php', encoding='utf-8').read()

# 插桩1: 路由进入
m1 = "Route::post('/ai/models', function (Request $r) {"
d1 = "Route::post('/ai/models', function (Request $r) {\n        @file_put_contents('/tmp/aimodels_dbg.log', date('c').' HIT-ROUTE-MODELS\\n', FILE_APPEND);"
assert src.count(m1) == 1
src = src.replace(m1, d1, 1)

# 插桩2: catch Exception 和 not successful 分支都打日志
old_block = """        } catch (\\Throwable $e) {
            return response()->json(['code'=>1,'message'=>'无法连接大模型接口: '.mb_substr($e->getMessage(), 0, 160)],502);
        }
        if (! $resp->successful()) {
            return response()->json(['code'=>1,'message'=>'获取模型列表 HTTP '.$resp->status().': '.mb_substr($resp->body(), 0, 200)],502);"""
assert src.count(old_block) == 1
new_block = """        } catch (\\Throwable $e) {
            @file_put_contents('/tmp/aimodels_dbg.log', date('c').' CATCH-EXC class='.get_class($e).' msg='.mb_substr($e->getMessage(),0,150)."\\n", FILE_APPEND);
            return response()->json(['code'=>1,'message'=>'无法连接大模型接口: '.mb_substr($e->getMessage(), 0, 160)],502);
        }
        if (! $resp->successful()) {
            @file_put_contents('/tmp/aimodels_dbg.log', date('c').' NOT-SUCCESS status='.$resp->status()."\\n", FILE_APPEND);
            return response()->json(['code'=>1,'message'=>'获取模型列表 HTTP '.$resp->status().': '.mb_substr($resp->body(), 0, 200)],502);"""
src = src.replace(old_block, new_block, 1)

open('/tmp/api_instr.php', 'w', encoding='utf-8').write(src)
print('插桩完成 md5:', hashlib.md5(src.encode('utf-8')).hexdigest()[:10])
r = subprocess.run(['php', '-l', '/tmp/api_instr.php'], capture_output=True, text=True)
print('lint:', r.stdout.strip())
with open('/tmp/api_instr.b64', 'w') as f:
    f.write(base64.b64encode(src.encode('utf-8')).decode())
print('b64 已写')