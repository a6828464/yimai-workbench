# -*- coding: utf-8 -*-
src = open('backend/routes/api.php', encoding='utf-8').read()

# 只替换 AI 段的 abort（用精确多行块替换）
repl = [
    # 1. /ai/chat 连接失败
    ("            abort(502, '无法连接大模型接口: '.mb_substr($e->getMessage(), 0, 160));",
     "            return response()->json(['code' => 1, 'message' => '无法连接大模型接口: '.mb_substr($e->getMessage(), 0, 160)], 502);"),
    # 2. /ai/chat 上游非2xx
    ("            abort(502, '大模型返回 HTTP '.$resp->status().': '.mb_substr($resp->body(), 0, 250));",
     "            return response()->json(['code' => 1, 'message' => '大模型返回 HTTP '.$resp->status().': '.mb_substr($resp->body(), 0, 250)], 502);"),
    # 3. /ai/chat content null
    ("        abort_if($content === null, 502, '大模型响应缺少内容: '.mb_substr($resp->body(), 0, 150));",
     "        if ($content === null) return response()->json(['code' => 1, 'message' => '大模型响应缺少内容: '.mb_substr($resp->body(), 0, 150)], 502);"),
    # 4. /ai/chat 校验
    ("        abort_unless(str_starts_with($d['baseUrl'], 'https://'), 422, '接口地址必须为 https');\n\n        // 推理模型",
     "        if (! str_starts_with($d['baseUrl'], 'https://')) return response()->json(['code' => 1, 'message' => '接口地址必须为 https'], 422);\n\n        // 推理模型"),
    # 5. /ai/models 连接失败
    ("            abort(502, '无法连接大模型接口: '.mb_substr($e->getMessage(), 0, 160));",
     "            return response()->json(['code' => 1, 'message' => '无法连接大模型接口: '.mb_substr($e->getMessage(), 0, 160)], 502);"),
    # 6. /ai/models 上游非2xx
    ("            abort(502, '获取模型列表 HTTP '.$resp->status().': '.mb_substr($resp->body(), 0, 200));",
     "            return response()->json(['code' => 1, 'message' => '获取模型列表 HTTP '.$resp->status().': '.mb_substr($resp->body(), 0, 200)], 502);"),
    # 7. /ai/models 校验
    ("        abort_unless(str_starts_with($d['baseUrl'], 'https://'), 422, '接口地址必须为 https');\n\n        try {",
     "        if (! str_starts_with($d['baseUrl'], 'https://')) return response()->json(['code' => 1, 'message' => '接口地址必须为 https'], 422);\n\n        try {"),
    # 8. /ky/session 和 /ky/call 的 abort(502) 也改（让错误可读）
    ("            abort(502, $e->getMessage());",
     "            return response()->json(['code' => 1, 'message' => $e->getMessage()], 502);"),
]

used = 0
for old, new in repl:
    n = src.count(old)
    if n:
        src = src.replace(old, new)
        used += n
    else:
        print('未命中:', old[:50])

open('backend/routes/api.php', 'w', encoding='utf-8').write(src)
print(f'共替换 {used} 处')

import subprocess, hashlib
r = subprocess.run(['php', '-l', 'backend/routes/api.php'], capture_output=True, text=True)
print('lint:', r.stdout.strip())
print('剩余AI段abort:', src.count("abort(502"))
print('md5:', hashlib.md5(src.encode('utf-8')).hexdigest()[:10])