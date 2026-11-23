<!--
  留资体验课数据清理（v3.19.0 · t16）
  ======================================
  馆主要的是「点一下把脏数据清掉」，不是看运维命令。但生产库没有直连通道（SSH 不在手边、
  3306 不对外开放），所以把 `POST /system/clean-trial-time`（后端 t12 已落地）做成卡片：
  **预演 → 二次确认 → 执行 → 再预演复验**。

  三条设计口径：
  1. **默认不改数据**：进页面什么都不做；「预演」只统计不写库，写库必须再点「执行清理」
     并在确认框里确认一次（confirm 文案带上一步预演命中的条数，避免盲点）。
  2. **说人话**：界面不出现 `dryRun` / `fieldHits` / `staleOwnerRows` 这类字段名；
     脏值讲成「日期那里显示成 undefined」，字段讲成「日期/时间/课程/老师/券名/券码」。
  3. **做完要能自证**：执行完显示写入行数，并给「复验」按钮再跑一次预演 —— 命中 0 条才算清完
     （后端清洗是幂等的：清过的值不再含脏 token，所以第二次一定命中 0）。
-->
<template>
  <!-- v-roles：非超管直接不渲染（页面路由本身也限 SUPER，这里是双保险；接口层还有 requireSuper） -->
  <ElCard
    v-roles="'R_SUPER'"
    shadow="never"
    class="mb-3"
    style="flex: 0 0 auto"
    data-test="lead-trial-clean-card"
  >
    <template #header>
      <div class="flex-cb flex-wrap gap-2">
        <div class="flex items-center gap-2 flex-wrap">
          <span class="font-500">留资体验课数据清理</span>
          <ElTag v-if="appliedRows !== null" size="small" type="success">已执行清理</ElTag>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <ElButton size="small" :loading="action === 'dry'" :disabled="busy" @click="preview">
            预演（只看不改）
          </ElButton>
          <ElButton
            size="small"
            type="danger"
            plain
            :loading="action === 'apply'"
            :disabled="busy"
            @click="apply"
          >
            执行清理
          </ElButton>
        </div>
      </div>
    </template>

    <p class="text-xs leading-5 text-g-500 m-0">
      清理留资体验课记录里因历史 bug 写进去的「undefined /
      null」脏东西（这些卡片上，日期、时间、课程、老师、券名、券码可能直接显示成 undefined）。
      <b class="text-g-700">只清这几个字段，其它字段不动，已成交金额也不动。</b>
      建议顺序：先点「预演」看命中多少条、长什么样，再点「执行清理」（会二次确认，确认后才真正改数据）。
    </p>

    <!-- ==================== 结果区 ==================== -->
    <div v-if="result" class="mt-3">
      <!-- 命中 0：既可能是本来就干净，也可能是清理后的复验通过 -->
      <ElAlert
        v-if="result.hitRows === 0"
        :title="
          result.mode === 'apply'
            ? '执行完成：已写入 0 行（没有需要清理的脏值）'
            : appliedRows !== null
              ? '复验通过：这次预演命中 0 条，脏值已清干净'
              : '没有发现脏值：体验课记录是干净的，不需要清理'
        "
        type="success"
        show-icon
        :closable="false"
      />
      <template v-else>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
            <div class="text-xs text-gray-400">命中记录（会员）</div>
            <div class="text-lg font-600 mt-1 tabular-nums">{{ result.hitRows }} 条</div>
            <div class="text-xs text-gray-400 mt-0.5">共检查 {{ result.scannedRows }} 条</div>
          </div>
          <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
            <div class="text-xs text-gray-400">体验课卡片字段</div>
            <div class="text-lg font-600 mt-1 tabular-nums">{{ result.cardHitRows }} 条</div>
            <div class="text-xs text-gray-400 mt-0.5">日期/时间/课程/老师/券名/券码</div>
          </div>
          <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
            <div class="text-xs text-gray-400">体验课老师（汇总）</div>
            <div class="text-lg font-600 mt-1 tabular-nums">{{ result.teacherHitRows }} 条</div>
            <div class="text-xs text-gray-400 mt-0.5">课程列表里那位老师的名字</div>
          </div>
          <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
            <div class="text-xs text-gray-400">
              {{ result.mode === 'apply' ? '本次已写入' : '本次未写库' }}
            </div>
            <div
              class="text-lg font-600 mt-1 tabular-nums"
              :class="result.mode === 'apply' ? 'text-green-600' : 'text-gray-400'"
            >
              {{ result.mode === 'apply' ? `${result.writtenRows} 行` : '0 行' }}
            </div>
            <div class="text-xs text-gray-400 mt-0.5">
              {{ result.mode === 'apply' ? '已落库并记入清理审计' : '预演只统计，不改任何数据' }}
            </div>
          </div>
        </div>

        <!-- 按字段分布：只显示命中过的字段（全 0 的字段不占地方） -->
        <div v-if="fieldRows.length" class="mt-3 flex items-center gap-2 flex-wrap">
          <span class="text-xs text-gray-400">脏值出现在：</span>
          <ElTag v-for="f in fieldRows" :key="f.key" size="small" type="warning" effect="plain">
            {{ f.label }} {{ f.count }} 处
          </ElTag>
        </div>

        <!-- 前 3 条样例：id + 字段 清前 → 清后 -->
        <template v-if="sampleRows.length">
          <div class="text-xs text-gray-400 mt-3 mb-1">
            {{
              sampleCount > 1 ? `前 ${sampleCount} 条样例` : '样例'
            }}（清前是现在库里的值，清后是清理后的值）
          </div>
          <!-- 手持端：一条样例一行，避免宽表横向滚动 -->
          <div v-if="isHandheld" class="flex flex-col gap-1">
            <div
              v-for="(row, i) in sampleRows"
              :key="`${row.id}-${i}`"
              class="text-xs leading-5 rounded bg-gray-50 dark:bg-gray-800 p-2"
            >
              <span class="text-gray-400">会员 #{{ row.id }} · {{ row.field }}：</span>
              <span class="text-red-500">{{ row.before }}</span>
              <span class="text-gray-400"> → </span>
              <span class="text-green-600">{{ row.after }}</span>
            </div>
          </div>
          <ElTable v-else :data="sampleRows" size="small" border max-height="240">
            <ElTableColumn prop="id" label="会员" width="90" />
            <ElTableColumn prop="field" label="所在位置" width="180" />
            <ElTableColumn prop="before" label="清前" min-width="160" show-overflow-tooltip>
              <template #default="{ row }">
                <span class="text-red-500">{{ row.before }}</span>
              </template>
            </ElTableColumn>
            <ElTableColumn prop="after" label="清后" min-width="140" show-overflow-tooltip>
              <template #default="{ row }">
                <span class="text-green-600">{{ row.after }}</span>
              </template>
            </ElTableColumn>
          </ElTable>
        </template>

        <!-- 「脏名 + 残留归属 id」：只统计，不修改（后端类注释写明它参与可见性，需人工判断） -->
        <ElAlert
          v-if="result.staleOwnerRows > 0"
          class="mt-3"
          type="warning"
          show-icon
          :closable="false"
          :title="`另有 ${result.staleOwnerRows} 条记录：体验课老师名字是脏值、但还挂着某位老师的归属（这类只统计，不自动改）`"
        >
          <div class="text-xs leading-5">
            归属 id
            决定这位老师能不能看到这批客资，贸然清空会让他忽然看不到自己的客户，所以这里只提示、不动它。
            需要清理请先看样例再决定（会员
            {{ staleOwnerIds }} 等）。
          </div>
        </ElAlert>

        <!-- 执行完成后的下一步：再预演一次确认命中 0（幂等） -->
        <div
          v-if="result.mode === 'apply'"
          class="mt-3 flex items-center gap-2 flex-wrap text-xs text-g-500"
        >
          <span
            >已处理 {{ result.writtenRows }} 行。再点一次「预演」，命中 0
            条就说明清完了（同一条脏数据不会被重复处理）。</span
          >
          <ElButton size="small" :loading="action === 'dry'" :disabled="busy" @click="preview">
            复验（再预演）
          </ElButton>
        </div>
      </template>
    </div>
  </ElCard>
</template>

<script setup lang="ts">
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { toHttpError } from '@/api/backend'
  import { TRIAL_CLEAN_FIELDS, cleanLeadTrialTime } from '@/api/yimai'
  import type { TrialCleanResult } from '@/api/yimai'
  import { useDevice } from '@/hooks/core/useDevice'

  defineOptions({ name: 'LeadTrialCleanCard' })

  const { isHandheld } = useDevice()

  /**
   * 字段 → 人话标签。
   * 键与后端 `LeadTrialTimeCleaner::CARD_FIELDS` 对齐（同一个 `TRIAL_CLEAN_FIELDS` 常量），
   * 服务端将来多出字段时走下面的兜底（直接显示原始键名，不会静默吞掉）。
   */
  const FIELD_LABELS: Record<string, string> = {
    date: '日期',
    time: '时间',
    topic: '课程',
    teacher: '老师',
    couponName: '券名',
    voucherCode: '券码'
  }

  /** 卡片上最多展开多少行样例（3 条记录 × 若干字段，避免结果区把页面撑爆） */
  const SAMPLE_ROW_LIMIT = 12

  const busy = ref(false)
  /** 当前进行中的动作：dry=预演 / apply=执行。用于按钮 loading 分流（两个按钮不能同时转） */
  const action = ref<'' | 'dry' | 'apply'>('')
  const result = ref<TrialCleanResult | null>(null)
  /** 上一次「执行清理」写入的行数（null = 本次页面还没执行过，用来区分「本来就干净」与「清完复验」） */
  const appliedRows = ref<number | null>(null)

  /** 按字段命中分布（只保留命中 >0 的，顺序按契约字段表，未知字段排后面） */
  const fieldRows = computed(() => {
    const hits = result.value?.fieldHits ?? {}
    const known = TRIAL_CLEAN_FIELDS.filter((f) => Number(hits[f] ?? 0) > 0).map((f) => ({
      key: f as string,
      label: FIELD_LABELS[f] ?? f,
      count: Number(hits[f] ?? 0)
    }))
    const extra = Object.entries(hits)
      .filter(([k, v]) => !(TRIAL_CLEAN_FIELDS as readonly string[]).includes(k) && Number(v) > 0)
      .map(([k, v]) => ({ key: k, label: FIELD_LABELS[k] ?? k, count: Number(v) }))
    return [...known, ...extra]
  })

  /**
   * 样例路径 → 人话位置。
   * 后端给的键形如 `cards[0].date`（第几张体验课卡片的哪个字段）与 `trial_teacher`（汇总列）。
   */
  function pathText(path: string): string {
    const mirror = path.match(/^cards\[(\d+)\]\.(\w+)$/)
    if (mirror) {
      const index = Number(mirror[1]) + 1
      return `第 ${index} 张卡片的${FIELD_LABELS[mirror[2]] ?? mirror[2]}`
    }
    if (path === 'trial_teacher') return '体验课老师（汇总）'
    return FIELD_LABELS[path] ?? path
  }

  /** 值 → 人话（空/缺失一律说「（空）」，长文本截断，避免撑破单元格） */
  function valueText(value: unknown): string {
    if (value === null || value === undefined) return '（空）'
    const text = String(value).replace(/\s+/g, ' ').trim()
    if (!text) return '（空）'
    return text.length > 28 ? `${text.slice(0, 28)}…` : text
  }

  /** 样例拍平成「会员 + 位置 + 清前 → 清后」的行（一条记录可能同时脏好几个字段） */
  const sampleRows = computed(() => {
    const rows: { id: number; field: string; before: string; after: string }[] = []
    for (const sample of result.value?.samples ?? []) {
      for (const [path, pair] of Object.entries(sample.changed ?? {})) {
        // 后端给的是 [清前, 清后]；形状异常时按「只有清前」展示，不编造清后值
        const before = Array.isArray(pair) ? pair[0] : undefined
        const after = Array.isArray(pair) ? pair[1] : undefined
        rows.push({
          id: sample.id,
          field: pathText(path),
          before: valueText(before),
          after: valueText(after)
        })
        if (rows.length >= SAMPLE_ROW_LIMIT) return rows
      }
    }
    return rows
  })

  /** 样例覆盖的记录条数（后端默认给 3 条，这里只用于文案里的「前 N 条」） */
  const sampleCount = computed(() => result.value?.samples?.length ?? 0)

  /** 「脏名 + 残留归属」样例里的会员 id（最多列 3 个，只做提示） */
  const staleOwnerIds = computed(() =>
    (result.value?.staleOwnerSamples ?? [])
      .map((s) => `#${s.id}`)
      .slice(0, 3)
      .join('、')
  )

  /** 错误文案：401 说会话失效，其余透出后端原话（403 会是「仅超管可用」这类中文），最后才兜底 */
  function errorText(e: unknown): string {
    const err = toHttpError(e)
    if (err.status === 401) return '登录已失效，请重新登录后再试'
    if (err.message && err.message !== '请求失败') return err.message
    return '请求失败，请稍后重试'
  }

  /** 跑一次（dryRun=true 只统计；false 真正写库） */
  async function run(dryRun: boolean) {
    busy.value = true
    action.value = dryRun ? 'dry' : 'apply'
    try {
      const data = await cleanLeadTrialTime({ dryRun, samples: 3 })
      result.value = data
      if (dryRun) {
        // 预演不改数据，所以上一次的「已执行」标记不该被这次预演抹掉语义：
        // appliedRows 保留，结果区据此把「命中 0」说成「复验通过」而不是「本来就干净」
        if (data.hitRows === 0) ElMessage.success('预演完成：没有命中脏值')
        else ElMessage.success(`预演完成：命中 ${data.hitRows} 条（未改动任何数据）`)
      } else {
        appliedRows.value = data.writtenRows
        ElMessage.success(
          data.writtenRows > 0
            ? `清理完成：已写入 ${data.writtenRows} 行`
            : '执行完成：没有需要清理的脏值（0 行写入）'
        )
      }
    } catch (e) {
      ElMessage.error(`${dryRun ? '预演' : '执行清理'}失败：${errorText(e)}`)
    } finally {
      busy.value = false
      action.value = ''
    }
  }

  function preview() {
    return run(true)
  }

  /** 执行清理：必须过二次确认（确认文案带上一步预演结果，避免「盲点」） */
  async function apply() {
    const dry = result.value?.mode === 'dry-run' ? result.value : null
    const scopeLine = dry
      ? dry.hitRows > 0
        ? `上一步预演命中 ${dry.hitRows} 条记录，执行后会把这些脏值清成空值（只动日期/时间/课程/老师/券名/券码）。`
        : '上一步预演命中 0 条，现在执行大概率什么也不会改。'
      : '还没预演过，建议先点「预演」看一眼命中多少条。'
    try {
      await ElMessageBox.confirm(
        `${scopeLine}\n\n确定现在执行吗？执行会真正修改数据（已成交金额、其它字段都不动），并记一条清理审计。`,
        '确认执行清理',
        {
          type: 'warning',
          confirmButtonText: '确认清理',
          cancelButtonText: '先不清理',
          // 换行符在 ElMessageBox 里默认不生效，显式保留空白才能让两段分开
          customStyle: { whiteSpace: 'pre-line' }
        }
      )
    } catch {
      return // 取消：什么都不做
    }
    await run(false)
  }
</script>
