#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
把当前版本发布到 Gitee（发行包附件）

## 为什么需要这个脚本

Gitee 免费仓库有 **1GB 附件配额**。原先的做法是每个版本往版本化 release 里传 4 个文件
（升级包 + 安装包，各再存一份 `-latest` 固定名副本），安装包单个 11MB —— 累积到 v3.1.54
左右就撑爆了配额，此后连续 7 个版本发行包都没能传上去；而 workflow 里这步是
`continue-on-error`，所以一直静默失败，只有 GitHub 那边正常。

现在的策略：

1. **版本化 release 只放 2 个版本化包**：升级包 + 安装包，不再放 `-latest` 副本（纯冗余）。
2. **`auto-latest` release 承载固定名包**，每次覆盖。服务器 `update.sh` 回退下载的正是
   `releases/download/auto-latest/yimai-workbench-latest.zip`（见 update.sh 的
   `GITEE_PACKAGE_URL`）。此前 workflow 只更新 GitHub 的 auto-latest，Gitee 那份一直停在
   旧包上（实测 2026-09-12），回退时会装到过期版本。
3. **回收旧安装包**：只保留最近 `KEEP_INSTALLERS` 个版本的安装包。升级包只有 2.9MB，全留。

稳态占用约 `(2.9 + 11) × KEEP_INSTALLERS + 13.9` MB，KEEP_INSTALLERS=20 时约 292MB。

## 用法

    GITEE_TOKEN=xxx python3 scripts/publish-gitee.py            # 正式发布
    GITEE_TOKEN=xxx python3 scripts/publish-gitee.py --dry-run  # 只打印将做什么
"""

import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

API = "https://gitee.com/api/v5"
REPO = os.environ.get("GITEE_REPO", "meng-taoo/yimai-workbench")
# 保留最近多少个版本的安装包（11MB/个，配额大头）；升级包不受影响
KEEP_INSTALLERS = int(os.environ.get("KEEP_INSTALLERS", "20"))
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
# 发行包所在目录。CI 里由 assemble 步骤生成在仓库根目录；本地验证时可指向别处
PKG_DIR = os.environ.get("PKG_DIR") or ROOT

LATEST = "yimai-workbench-latest.zip"
LATEST_INSTALLER = "yimai-workbench-installer-latest.zip"

DRY = False
TOKEN = ""
# 不走系统代理：Gitee 应直连（CI runner 上无所谓，本地开发机可能挂着代理）
OPENER = urllib.request.build_opener(urllib.request.ProxyHandler({}))


def log(msg=""):
    print(msg, flush=True)


def warn(msg):
    print(f"⚠ {msg}", file=sys.stderr, flush=True)


def http(method, path, params=None, body=None, ctype=None, timeout=600):
    params = dict(params or {})
    params["access_token"] = TOKEN
    qs = urllib.parse.urlencode({k: v for k, v in params.items() if v is not None})
    if method == "POST" and body is None:
        # Gitee 的创建类接口吃表单体
        body = qs.encode()
        ctype = ctype or "application/x-www-form-urlencoded"
        qs = ""
    url = f"{API}{path}" + (f"?{qs}" if qs else "")
    req = urllib.request.Request(url, data=body, method=method)
    if ctype:
        req.add_header("Content-Type", ctype)
    with OPENER.open(req, timeout=timeout) as resp:
        raw = resp.read()
    return json.loads(raw) if raw else {}


def multipart(path, filename, filepath):
    """手写 multipart，避免给 CI 引入第三方依赖。"""
    boundary = f"----yimai{int(time.time() * 1000)}"
    body = (
        f'--{boundary}\r\nContent-Disposition: form-data; name="access_token"\r\n\r\n{TOKEN}\r\n'
    ).encode()
    body += (
        f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\n'
        f"Content-Type: application/zip\r\n\r\n"
    ).encode()
    with open(filepath, "rb") as fh:
        body += fh.read()
    body += f"\r\n--{boundary}--\r\n".encode()
    return http("POST", path, body=body, ctype=f"multipart/form-data; boundary={boundary}")


def list_releases():
    out, page = [], 1
    while True:
        batch = http("GET", f"/repos/{REPO}/releases", {"page": page, "per_page": 100})
        if not batch:
            break
        out += batch
        if len(batch) < 100:
            break
        page += 1
    return out


def list_assets(rid):
    try:
        return http("GET", f"/repos/{REPO}/releases/{rid}/attach_files")
    except Exception as e:  # 单个 release 查不到不应中断整轮发布
        warn(f"列出 release {rid} 的附件失败：{e}")
        return []


def delete_asset(rid, aid, label):
    if DRY:
        log(f"  [dry-run] 删除 {label}")
        return True
    try:
        http("DELETE", f"/repos/{REPO}/releases/{rid}/attach_files/{aid}")
        return True
    except Exception as e:
        warn(f"删除 {label} 失败：{e}")
        return False


def upload(rid, filename, label=None, as_name=None):
    """把 filename 传到 release 上。as_name 用于重命名（Gitee 的附件名取自 multipart filename）。"""
    label = label or filename
    name = as_name or filename
    path = os.path.join(PKG_DIR, filename)
    if not os.path.exists(path):
        warn(f"本地找不到 {path}，跳过 {label}")
        return False
    size = os.path.getsize(path)
    if DRY:
        log(f"  [dry-run] 上传 {label}（{size / 1024 / 1024:.1f}MB）")
        return True
    for attempt in range(1, 6):
        try:
            multipart(f"/repos/{REPO}/releases/{rid}/attach_files", name, path)
            log(f"  ✓ {label}（{size / 1024 / 1024:.1f}MB）")
            return True
        except urllib.error.HTTPError as e:
            detail = e.read().decode("utf-8", "replace")[:200]
            if e.code == 400:
                # 400 基本是配额满或文件不合法，重试无意义，直接报出来
                warn(f"{label} 上传被拒（HTTP 400）：{detail}")
                return False
            warn(f"{label} 第 {attempt}/5 次失败：HTTP {e.code} {detail}")
        except Exception as e:
            warn(f"{label} 第 {attempt}/5 次失败：{e}")
        if attempt < 5:
            time.sleep([5, 15, 30, 60][attempt - 1])
    warn(f"{label} 连续 5 次失败，跳过")
    return False


def ensure_release(releases, tag, name, body):
    """找到同 tag 的 release 则复用（保留已有附件），否则新建。"""
    found = next((str(r["id"]) for r in releases if r.get("tag_name") == tag), "")
    if found:
        return found
    if DRY:
        log(f"  [dry-run] 新建 release {tag}")
        return None
    resp = http(
        "POST",
        f"/repos/{REPO}/releases",
        {"tag_name": tag, "name": name, "body": body, "target_commitish": "main"},
    )
    return str(resp["id"])


def version_of(name):
    m = re.search(r"v(\d+)\.(\d+)\.(\d+)", name or "")
    return tuple(int(x) for x in m.groups()) if m else (0, 0, 0)


def read_changelog():
    with open(os.path.join(ROOT, "CHANGELOG.md"), encoding="utf-8") as fh:
        text = fh.read()
    m = re.search(r"v(\d+\.\d+\.\d+)", text)
    if not m:
        raise RuntimeError("CHANGELOG.md 里找不到版本号")
    # 取首个版本标题下的正文作为 release 说明
    lines, seen = [], 0
    for line in text.splitlines():
        if line.startswith("## "):
            seen += 1
            if seen == 1:
                continue
            if seen == 2:
                break
        if seen == 1:
            lines.append(line)
    return m.group(1), "\n".join(l for l in lines if l.strip())


def main():
    global DRY, TOKEN

    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    DRY = args.dry_run
    TOKEN = os.environ.get("GITEE_TOKEN", "")

    if not TOKEN:
        log("GITEE_TOKEN 未配置，跳过 Gitee 发布")
        return 0

    version, notes = read_changelog()
    tag = f"v{version}"
    body = (
        f"# 一麦工作台 v{version}\n\n{notes}\n---\n"
        f"由 main 分支提交 {os.environ.get('GITHUB_SHA', '')} 自动构建。后台更新请使用该发行包。"
    )

    log(f"── 目标版本 v{version}")
    releases = list_releases()
    log(f"   Gitee 上共 {len(releases)} 个 release")

    versioned = [r for r in releases if re.fullmatch(r"v\d+\.\d+\.\d+", r.get("tag_name") or "")]
    versioned.sort(key=lambda r: version_of(r.get("tag_name")), reverse=True)
    keep = {r["tag_name"] for r in versioned[:KEEP_INSTALLERS]} | {tag}

    # ---------------------------------------------------------------- 回收
    log(f"\n── 1/4 回收旧附件（安装包只留最近 {KEEP_INSTALLERS} 个版本）")
    freed = removed = 0
    for r in versioned:
        rtag, rid = r.get("tag_name"), r.get("id")
        for a in list_assets(rid):
            name = a.get("name", "")
            if name == LATEST:
                why = "冗余的 latest 副本（auto-latest 那份才是回退用的）"
            elif rtag not in keep and "installer" in name:
                why = "超出保留窗口的安装包"
            else:
                continue
            if delete_asset(rid, a["id"], f"{rtag}/{name}（{why}）"):
                freed += a.get("size") or 0
                removed += 1
    log(f"   删除 {removed} 个附件，释放约 {freed / 1024 / 1024:.0f} MB")

    # ------------------------------------------------------------ 版本化发布
    log(f"\n── 2/4 发布 {tag}")
    rid = ensure_release(releases, tag, f"一麦工作台 {tag}", body)
    existing = {a.get("name") for a in list_assets(rid)} if rid else set()
    for f in (f"yimai-workbench-v{version}.zip", f"yimai-workbench-installer-v{version}.zip"):
        if f in existing:
            log(f"  跳过 {f}（已存在）")
        elif rid:
            upload(rid, f)

    # ------------------------------------------------------------ auto-latest
    log("\n── 3/4 刷新 auto-latest（服务器 update.sh 的回退下载源）")
    arid = ensure_release(releases, "auto-latest", "一麦工作台（最新）", body)
    if arid:
        # 先判断是否已经是本版本的包：内容一致就别动，避免「删了旧的、新的又传失败」
        # 导致回退地址 404。只有确认需要更新时才走「先删后传」。
        want = os.path.join(PKG_DIR, f"yimai-workbench-v{version}.zip")
        if not os.path.exists(want):
            # 本地没有包时绝不能往下走：下面的逻辑是「先删旧再传新」，
            # 删完传不上回退地址就 404 了。宁可不动，也不要留下坏的回退源。
            warn(f"本地缺少 {want}，跳过 auto-latest 刷新（保持现状）")
            arid = None
        want_size = os.path.getsize(want) if os.path.exists(want) else -1
        current = {a.get("name"): (a.get("size") or 0, a["id"]) for a in list_assets(arid)} if arid else {}
        # 历史/误传的版本化命名附件不该留在 auto-latest 上（固定名才是回退地址要的）
        for nm, (_sz, aid) in list(current.items()):
            if re.fullmatch(r"yimai-workbench(-installer)?-v\d+\.\d+\.\d+\.zip", nm):
                delete_asset(arid, aid, f"auto-latest/{nm}（应改为固定名）")
                current.pop(nm, None)
        if current.get(LATEST, (0, ""))[0] == want_size and want_size > 0:
            log(f"  auto-latest 已是 v{version} 的包（{want_size / 1024 / 1024:.1f}MB），跳过")
        else:
            for name in (LATEST, LATEST_INSTALLER):
                if name in current:
                    delete_asset(arid, current[name][1], f"auto-latest/{name}（旧包，将被覆盖）")
            upload(arid, f"yimai-workbench-v{version}.zip", f"{LATEST}（v{version}）", as_name=LATEST)
            upload(
                arid,
                f"yimai-workbench-installer-v{version}.zip",
                f"{LATEST_INSTALLER}（v{version}）",
                as_name=LATEST_INSTALLER,
            )

    # ---------------------------------------------------------------- 汇总
    log("\n── 4/4 配额占用")
    total = sum(a.get("size") or 0 for r in list_releases() for a in list_assets(r["id"]))
    log(f"   当前 Gitee 附件合计约 {total / 1024 / 1024:.0f} MB / 1024 MB 配额")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as e:  # noqa: BLE001 —— 发布失败不该阻断构建（workflow 侧也设了 continue-on-error）
        warn(f"Gitee 发布失败：{e}")
        sys.exit(0)
