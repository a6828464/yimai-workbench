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
    parser.add_argument("--quota", action="store_true", help="额外统计 Gitee 附件总体积（较慢）")
    args = parser.parse_args()
    DRY = args.dry_run
    TOKEN = os.environ.get("GITEE_TOKEN", "")

    if not TOKEN:
        log("GITEE_TOKEN 未配置，跳过 Gitee 发布")
        return 0

    # 计数「本该传上去但没成功」的文件。以非 0 退出而不是静默成功 ——
    # 此前顶层 except 一律 sys.exit(0)，Gitee 连续 7 个版本没传上包都没人察觉。
    failed = 0

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
    # 只扫「保留窗口 + 余量」内的版本，不逐条查全部历史 release：
    # GitHub runner 访问 Gitee 每次 API 约十几秒，62 个 release 能拖到 20 分钟。
    # 窗口外的版本在上一次运行里已经清过，没有可回收的东西。
    scan = versioned[: KEEP_INSTALLERS + 10]
    log(f"\n── 1/4 回收旧附件（安装包只留最近 {KEEP_INSTALLERS} 个版本，扫描 {len(scan)} 个 release）")
    freed = removed = 0
    # 关键：release 列表里**已经带了附件名**，判断"这个 release 有没有东西要删"根本不需要
    # 逐条查附件。而 runner 访问 Gitee 每次 API 要十几秒，30 个 release 挨个查能拖十分钟。
    # 所以先用名字筛出真正要动的 release，只有那些才去取附件 id。
    scanned = 0
    for r in scan:
        rtag, rid = r.get("tag_name"), r.get("id")
        names = [a.get("name", "") for a in (r.get("assets") or [])]
        scanned += 1
        if LATEST not in names and not (rtag not in keep and any("installer" in n for n in names)):
            continue
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
    log(f"   检查 {scanned} 个 release，删除 {removed} 个附件，释放约 {freed / 1024 / 1024:.0f} MB")

    # ------------------------------------------------------------ 版本化发布
    # 同名附件不能只按「存在就跳过」处理：Gitee 的下载地址会解析到已有的那一份，
    # 而工作流被中途取消 / 手动重跑时，上一轮构建的包往往已经传上去了 —— 实测 v3.1.63
    # 就在 Gitee 上留着「已被 revert 的版本」，与 GitHub 的包不是同一个 commit，
    # 而 DEPLOY.md 把两者当作同一份下载源。所以按体积判断内容是否变化，变了就删旧重传。
    log(f"\n── 2/4 发布 {tag}")
    rid = ensure_release(releases, tag, f"一麦工作台 {tag}", body)
    existing = {a.get("name"): a for a in (list_assets(rid) if rid else [])}
    for f in (f"yimai-workbench-v{version}.zip", f"yimai-workbench-installer-v{version}.zip"):
        local = os.path.join(PKG_DIR, f)
        if not os.path.exists(local):
            warn(f"本地找不到 {local}，跳过 {f}")
            failed += 1
            continue
        same = existing.get(f)
        if same is not None and int(same.get("size") or 0) == os.path.getsize(local):
            log(f"  跳过 {f}（已存在且体积一致）")
            continue
        if same is not None:
            delete_asset(rid, same.get("id"), f"{tag}/{f}（同名旧包，内容已变）")
        if rid and not upload(rid, f):
            failed += 1

    # ------------------------------------------------------------ auto-latest
    log("\n── 3/4 刷新 auto-latest（服务器 update.sh 的回退下载源）")
    arid = ensure_release(releases, "auto-latest", "一麦工作台（最新）", body)
    if arid:
        # 先判断是否已经是本版本的包：内容一致就别动，避免「删了旧的、新的又传失败」
        # 导致回退地址 404。只有确认需要更新时才走「先删后传」。
        want = os.path.join(PKG_DIR, f"yimai-workbench-v{version}.zip")
        if not os.path.exists(want):
            warn(f"本地缺少 {want}，跳过 auto-latest 刷新（保持现状）")
            arid = None

        if arid:
            # 只传升级包（2.9MB）。安装包 11MB、经 GitHub runner 传到 Gitee 常常要十几分钟，
            # 而**没人从这个位置取安装包**：服务器 update.sh 的回退地址只下载
            # yimai-workbench-latest.zip；全新安装走版本化 release 里的安装包。
            #
            # 每次发布都传（不做"内容一样就跳过"的启发式判断）——判断靠尺寸，两个版本
            # 恰好同尺寸时会误判成"已是最新"，回退源就悄悄停在旧版本上，代价比省一次
            # 2.9MB 上传大得多。确定性优先。
            if not upload(arid, f"yimai-workbench-v{version}.zip", f"{LATEST}（v{version}）", as_name=LATEST):
                # 固定名包是服务器 update.sh 的回退下载源，传不上去必须让人知道
                failed += 1

            # 收尾：确保每个固定名**只留一份**（保留 id 最大的，即刚传的）。
            #
            # 为什么必须做：Gitee 在同名附件存在时，下载地址会解析到其中一份 —— 实测
            # 它挑的是旧那份。于是"先传新的再删旧的"如果删除这步没执行（CI 中途结束、
            # 请求失败），就会出现新旧同名共存，而回退地址安静地继续发旧版本。
            # 这里按名字分组、只留最新一份，既收尾也自愈历史遗留。
            after = {}
            for a in sorted(list_assets(arid), key=lambda x: x.get("id") or 0):
                after.setdefault(a.get("name", ""), []).append(a)
            # latest 只留最新一份；installer-latest 是历史遗留（现在不再传），整份清掉
            for a in after.get(LATEST, [])[:-1]:
                delete_asset(arid, a.get("id"), f"auto-latest/{LATEST}（同名旧附件，只保留最新一份）")
            for a in after.get(LATEST_INSTALLER, []):
                delete_asset(arid, a.get("id"), f"auto-latest/{LATEST_INSTALLER}（不再维护，清掉省配额）")

    # ---------------------------------------------------------------- 汇总
    #
    # 附件清单在列表里就有，数出来是免费的；精确体积要逐条查附件（runner 上很贵，62 次
    # 调用约十分钟），所以只在显式传 --quota 时才算。平时靠上传失败时 Gitee 返回的
    # 配额报错（HTTP 400 "超出仓库附件配额"）来告警，够用。
    total_assets = sum(
        len([n for n in (a.get("name", "") for a in (r.get("assets") or [])) if n.startswith("yimai-")])
        for r in releases
    )
    log("\n── 4/4 Gitee 附件概览")
    log(f"   我方发行包附件共 {total_assets} 个")
    if args.quota:
        log("   --quota：逐条统计体积（较慢）...")
        total = sum(a.get("size") or 0 for r in list_releases() for a in list_assets(r["id"]))
        log(f"   合计约 {total / 1024 / 1024:.0f} MB / 1024 MB 配额")

    if failed:
        warn(f"Gitee 发布未完成：{failed} 个发行包没能上传（详见上方日志）。Gitee 只是兜底下载源，")
        warn("生产在线升级走 GitHub auto-latest 不受影响，但请确认不是配额问题。")
        return 1

    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as e:  # noqa: BLE001
        # 以非 0 退出：这个脚本此前把所有异常都吞成 exit(0)，Gitee 连续 7 个版本没传上包
        # 都没人发现。workflow 侧仍是 continue-on-error（Gitee 不该阻断 GitHub 发布），
        # 但失败会在 Actions 里标出来，而不是显示成一片绿。
        warn(f"Gitee 发布失败：{e}")
        sys.exit(1)
