<template>
  <div class="page" :class="isWide ? 'is-web' : 'is-h5'">
    <!--
      草稿预览角标（`?preview=1` + 本机持有同一个码时才出现）。
      语义：本页数据来自**本机草稿**，客户看不到本页 —— 客户打开的是已发布快照。
      「先编辑预览、生成才算数」：预览零副作用（不发布、不改开关、不计访问量）。
    -->
    <div v-if="draftPreview" class="preview-bar">
      草稿预览（未发布）· 本页数据来自本机草稿，客户看不到；客户打开的是已发布内容
    </div>

    <!-- 分享码现在由服务端随机签发，本地初始码与 URL 必然不同，
         等待公开接口返回前先显示加载态，避免有效链接闪一下「已失效」 -->
    <div v-if="loading" class="flex-c h-100vh flex-col gap-3">
      <img src="@imgs/yimai-logo.png" class="w-14 h-14 object-contain" alt="一麦" />
      <p class="text-gray-400">正在加载…</p>
    </div>

    <div v-else-if="invalid" class="flex-c h-100vh flex-col gap-3">
      <img src="@imgs/yimai-logo.png" class="w-14 h-14 object-contain" alt="一麦" />
      <p class="text-gray-500">链接已失效或已停用，请联系门店获取最新资料</p>
    </div>

    <template v-else>
      <!--
        品牌头：info 整键可能缺失（见 script 里的降级说明），逐行判空后再渲染。
        主视觉 = 模板的 hero 图（按版式选路，`pickSalesHeroPath`）**优先**，空串时回落色调渐变。
        当前四套模板 `hero*Url` 都是空串 → 只出渐变；填上 URL 即出图，本组件无需改动。
        ⚠️ 模板只控制视觉，这里的**文字全部来自门店填写**（basic.vue 的 6 个字段）。
      -->
      <header class="hero" :class="[toneClass, isWide ? 'hero-web' : 'hero-h5']">
        <img v-if="heroSrc" class="hero-img" :src="heroSrc" alt="" />
        <!-- 标题是版式的视觉主体，缺 name 时用品牌名兜底，而不是留一条空白绿带 -->
        <h1 class="hero-title">{{ info.name || '一麦瑜伽' }}</h1>
        <p v-if="info.industry" class="hero-line">{{ info.industry }}</p>
        <p v-if="info.slogan" class="hero-slogan">「 {{ info.slogan }} 」</p>
        <p v-if="info.intro" class="hero-intro">{{ info.intro }}</p>
      </header>

      <!--
        双端版式（阶段 A：同一路由、同一份内容，两种呈现）：
          · 窄屏 / H5（默认）：单列，产品 → 教练 → 案例 → 到店信息
          · 宽屏 / 网页版：主列 + 侧栏（到店信息与合规说明移到侧栏，仍可点电话/复制地址）
        「到店信息」在两种版式下都**渲染一次**：横屏时它是侧栏，所以不出现在主列板块集合里
        （源：pages/sales-tool/viewer.js:387-397 —— 竖屏追加 location、横屏移除）。
      -->
      <div class="body" :class="{ 'is-wide': isWide }">
        <main class="col">
          <!-- 产品与价目：每个产品展示**自己的**独立价目表（cols/rows 各自一套） -->
          <section v-if="showProducts" class="sec">
            <h2 class="sec-title">产品与价目</h2>
            <div v-for="row in productRows" :key="row.id" class="card mb-3">
              <div class="flex items-center justify-between">
                <h3 class="font-600">{{ row.name }}</h3>
              </div>
              <p class="text-xs text-gray-500 mt-1 leading-5">{{ row.desc }}</p>
              <!-- 每产品的价目表独立：`table` 为 null = 该产品隐藏价目或无有效内容，
                   显示「价目详情请到店咨询」而不是一张空表。显隐是**内容层**开关，
                   换模板不得改变它（模板只控制视觉）。 -->
              <div v-if="row.table" class="table-wrap">
                <table class="price-table">
                  <thead>
                    <tr>
                      <th
                        v-for="(c, cix) in row.table?.cols || []"
                        :key="cix"
                        class="border border-gray-200 px-2 py-1.5 font-500 text-left"
                        >{{ c }}</th
                      >
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(cells, ci) in row.table?.rows || []" :key="ci">
                      <td
                        v-for="(cell, cj) in cells"
                        :key="cj"
                        class="border border-gray-200 px-2 py-1.5"
                        :class="{ 'font-600 text-green-800': String(cell).startsWith('¥') }"
                        >{{ cell }}</td
                      >
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-else class="mt-2 text-xs text-gray-400">价目详情请到店咨询</p>
            </div>
          </section>

          <!-- 推荐教练：同理，无教练时隐藏整节 -->
          <section v-if="showCoaches" class="sec">
            <h2 class="sec-title">推荐教练</h2>
            <div class="coach-grid">
              <div v-for="c in coaches" :key="c.id" class="card flex gap-3 items-start">
                <div class="coach-avatar flex-none">{{ (c.name || '·').slice(0, 1) }}</div>
                <div class="min-w-0">
                  <div class="flex items-baseline gap-2">
                    <span class="font-600">{{ c.name }}</span>
                    <span class="text-xs text-gray-400">{{ c.title }}</span>
                  </div>
                  <div class="mt-1 flex flex-wrap gap-1">
                    <span v-for="t in c.tags || []" :key="t" class="tag">{{ t }}</span>
                  </div>
                  <p class="text-xs text-gray-500 mt-1 leading-5">{{ c.intro }}</p>
                </div>
              </div>
            </div>
          </section>

          <!-- 学员案例（仅已授权） -->
          <section v-if="showCases" class="sec">
            <h2 class="sec-title">学员案例</h2>
            <p class="text-xs text-gray-400 mb-2"
              >以下案例由门店确认已取得会员授权 · 展示不含面部信息</p
            >

            <!--
            筛选（一麦补充能力；源：pages/sales-tool/viewer.js:479-483 `filterCasesByCoach`）。
            两个维度：
              · 按教练 —— 与小程序的真实筛选维度一致（教练筛选优先）；
              · 按目标 —— 筛选项就是门店填写的**目标原文**，不引入任何预设枚举
                （规格 §5.10：目标保持自由文本），因此不会限制运营表达。
            筛选项只列「确实有案例」的值，避免点进去是空列表。
          -->
            <div class="filters">
              <div class="flex gap-1" role="tablist" aria-label="案例筛选维度">
                <button
                  v-for="d in CASE_DIMENSIONS"
                  :key="d.id"
                  type="button"
                  role="tab"
                  class="chip chip-dim"
                  :class="{ 'is-active': caseDim === d.id }"
                  :aria-selected="caseDim === d.id"
                  @click="switchDim(d.id)"
                >
                  {{ d.label }}
                </button>
              </div>
              <div class="chip-row">
                <button
                  type="button"
                  class="chip"
                  :class="{ 'is-active': activeKey === '' }"
                  :aria-pressed="activeKey === ''"
                  @click="activeKey = ''"
                  >全部<span class="chip-n">{{ authedCases.length }}</span></button
                >
                <button
                  v-for="c in activeChips"
                  :key="c.key"
                  type="button"
                  class="chip"
                  :class="{ 'is-active': activeKey === c.key }"
                  :aria-pressed="activeKey === c.key"
                  @click="activeKey = c.key"
                >
                  {{ c.label }}<span class="chip-n">{{ c.count }}</span>
                </button>
              </div>
            </div>

            <p v-if="!filteredCases.length" class="text-xs text-gray-400 mb-2"
              >该筛选下暂无案例，换个维度看看</p
            >

            <div v-for="(c, ci) in filteredCases" :key="c.id" class="card mb-3">
              <div class="text-xs text-gray-400 mb-1">目标</div>
              <h3 class="font-600">{{ caseCardTitle(c, ci) }}</h3>
              <p v-if="caseCardMeta(c, coachName(c))" class="text-xs text-gray-400 mt-1">{{
                caseCardMeta(c, coachName(c))
              }}</p>
              <p class="text-xs text-gray-500 mt-1 leading-5">{{ c.desc }}</p>
              <div class="mt-2 flex flex-wrap gap-1.5">
                <ElTag
                  v-for="(s, si) in c.stages || []"
                  :key="si"
                  size="small"
                  :effect="si === 0 ? 'plain' : 'dark'"
                  type="success"
                >
                  {{ si === 0 ? '初始' : s.duration || `阶段${si}` }}
                </ElTag>
              </div>
            </div>
          </section>
        </main>

        <!--
          到店信息（= 小程序里的「我的位置」板块，源：viewer.js:387-397）。
          本块只渲染**一次**，落位由 `salesSections()` 决定：
            · 竖屏：它是正文末节（`loc-flow`，跟在案例后面）；
            · 横屏：它是右侧栏（`loc-rail` + `.body.is-wide` 把它放进第二列）。
          两种版式下地址 / 电话 / CTA 都可用 —— 横屏客户同样要能一键拨号，
          所以「移除 location」的语义是**换位置**，不是删内容。
        -->
        <section class="loc" :class="locationInFlow ? 'loc-flow' : 'loc-rail'">
          <h2 class="sec-title">到店信息</h2>
          <div v-if="info.address || info.phone" class="card space-y-2 text-sm">
            <p v-if="info.address">📍 {{ info.address }}</p>
            <p v-if="info.phone">📞 {{ info.phone }}</p>
          </div>
          <div v-else class="card text-sm text-gray-400"
            >门店地址与电话暂未提供，可直接到店咨询</div
          >
          <!-- 电话/地址为空时不渲染对应按钮：空的 tel: 链接点下去会误拨、复制空串也无意义 -->
          <div v-if="telHref || copyableAddress" class="mt-4 grid grid-cols-2 gap-3">
            <a v-if="telHref" class="cta" :class="buttonClass" :href="telHref">电话咨询</a>
            <button
              v-if="copyableAddress"
              class="cta secondary"
              :class="buttonClass"
              @click="copyAddress"
              >复制地址</button
            >
          </div>
          <!-- 合规说明（S18）：对客文案。
               后端在「键缺失 / 空白（含全角空格、U+00A0）/ 非字符串」时**不下发该键**，
               所以这里必须按「键不存在即整块不渲染」处理 —— 渲染一个空卡片会让客户
               以为门店漏填了，而实际语义是「本店无需声明」。 -->
          <div v-if="complianceNotice" class="card mt-4 text-xs leading-5 text-gray-500">
            <p class="font-500 mb-1 text-gray-600">合规说明</p>
            <p class="whitespace-pre-wrap">{{ complianceNotice }}</p>
          </div>
          <p class="mt-4 text-center text-xs text-gray-400">© 一麦瑜伽 · 双店运营</p>
        </section>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
  import {
    useSalesStore,
    findSalesTemplate,
    salesTemplateToneClass,
    salesTemplateButtonClass,
    resolveSalesDisplayMode,
    salesSections,
    isSalesPreviewQuery,
    canUseDraftPreview,
    visibleCases,
    filterCasesByCoach,
    filterCasesByGoal,
    caseGoalChips,
    countCasesByCoach,
    caseCardTitle,
    caseCardMeta,
    productPriceTable,
    pickSalesHeroPath,
    type SalesCase,
    type SalesSectionKey
  } from '@/store/modules/sales'
  import axios from 'axios'
  import { ElMessage, ElTag } from 'element-plus'
  import { StorageConfig } from '@/utils/storage/storage-config'

  defineOptions({ name: 'SalesSharePage' })

  const route = useRoute()
  const sales = useSalesStore()

  /** 与 `store/modules/sales.ts` 的 `persist.key` 同值（落盘键的 store 段） */
  const SALES_STORE_ID = 'yimai-sales-store'

  function asString(v: unknown): string {
    return typeof v === 'string' ? v : ''
  }

  /**
   * 双端版式（阶段 A：同一路由、同一份内容，两种呈现）。
   *
   * 解析优先级（见 store 的 `resolveSalesDisplayMode`）：
   *   `?mode=h5|web` → 视口宽度（≥1024 视为网页横屏）→ 默认 h5。
   * **不传参数就是默认** —— 存量客户链接（URL 里没有任何 query）行为与升级前一致；
   * 版式与模板由管理端生成链接时带上（`sales/index.vue` 的「复制客户链接」）。
   */
  const viewportWidth = ref(typeof window === 'undefined' ? undefined : window.innerWidth)

  function onViewportResize() {
    viewportWidth.value = window.innerWidth
  }

  /**
   * 草稿预览（`?preview=1`）—— **两道门**：
   *  1. URL 显式要求预览；
   *  2. 本浏览器持有同一个分享码（`canUseDraftPreview`）。
   *
   * 第 2 条不可省：本模式把数据源从「服务端公开快照」换成「本机草稿」，
   * 若任何访客在任意链接后加个 `?preview=1` 就能进这个分支，客户会在**别人的正式链接**上
   * 看到本机的演示/陈旧数据 —— 比看不到更糟。本机码在发布成功时会被换成服务端签发的
   * 权威码，所以「URL 码 === 本机码」恰好等价于「我在自己浏览器里打开自己的链接」。
   */
  const wantsPreview = computed(() => isSalesPreviewQuery(route.query.preview))
  const draftPreview = computed(
    () =>
      wantsPreview.value &&
      canUseDraftPreview(String(route.params.code ?? ''), sales.state.share.code)
  )

  /**
   * 预览「即时生效」的载体：本机持久化草稿。
   *
   * 管理端在另一个标签页改模板 / 版式 / 内容时，pinia 持久化会把新状态写进
   * localStorage，浏览器随即在本页触发 `storage` 事件 —— 读回来即可让预览**免刷新**跟随。
   * 只在草稿预览模式使用：客户链接（无 `?preview=1`）永远不读本机数据。
   */
  /**
   * 本机持久化草稿的落盘键。
   *
   * ⚠️ **不是** `'yimai-sales-store'`。`store/modules/sales.ts` 里的 `persist.key` 只是
   * store 标识，pinia 持久化插件在 `store/index.ts:38` 统一把它交给 `StorageKeyManager`，
   * 最终落盘键形如 `sys-v{版本}-{storeId}`（版本 = `VITE_VERSION`，每版会变）。
   * 硬编码裸键会**永远读不到**（静默失效），所以这里从同一个 `StorageConfig` 派生 ——
   * 写入端与读取端同源，版本升级也不会漂移。
   */
  const liveDraftKey = StorageConfig.generateStorageKey(SALES_STORE_ID)
  const liveDraft = ref<Record<string, unknown> | null>(null)

  function readPersistedSalesDraft(): Record<string, unknown> | null {
    try {
      const raw = localStorage.getItem(liveDraftKey)
      if (!raw) return null
      const parsed: unknown = JSON.parse(raw)
      if (!parsed || typeof parsed !== 'object') return null
      // persisted 落盘的是 `store.$state`。本 store 把整份状态装在一个 `state` ref 里，
      // 所以**真实形状是 `{ state: {...} }`（嵌套一层）**。这里同时容忍扁平形状 ——
      // 形状判断错会让「即时跟随草稿」静默失效（取不到字段 → 全部回落内存 store）。
      const outer = parsed as Record<string, unknown>
      const inner = outer.state
      if (inner && typeof inner === 'object' && !Array.isArray(inner)) {
        return inner as Record<string, unknown>
      }
      return outer
    } catch {
      return null
    }
  }

  /** 跨标签页草稿：缺键时逐项回落内存 store，避免半份草稿把页面渲染成空 */
  const draftState = computed<Record<string, unknown>>(() => {
    const d = liveDraft.value
    if (!d) return sales.state as unknown as Record<string, unknown>
    return {
      info: d.info ?? sales.state.info,
      products: d.products ?? sales.state.products,
      coaches: d.coaches ?? sales.state.coaches,
      cases: d.cases ?? sales.state.cases,
      share: d.share ?? sales.state.share,
      templateId: d.templateId ?? sales.state.templateId,
      displayMode: d.displayMode ?? sales.state.displayMode
    }
  })

  function onStorage(e: StorageEvent) {
    if (!draftPreview.value) return
    // 只认本 store 的落盘键（版本前缀键，见 liveDraftKey 的说明）
    if (e.key && e.key !== liveDraftKey) return
    liveDraft.value = readPersistedSalesDraft()
  }

  /**
   * 快照形状声明为「宽松」的：公开接口只保证 `share` 键存在，其余四键可能整键缺失。
   * 这里刻意不写成「所有字段都必填」的强类型 —— 那等于对编译器撒谎，
   * 也掩盖了「后端可能少给键」这一事实（修复本页白屏正是因为假设了字段必在）。
   */
  interface ShareSnapshot {
    share?: Record<string, unknown> | null
    info?: Record<string, unknown> | null
    coaches?: unknown
    products?: unknown
    cases?: unknown
  }

  /** 数据：优先后端公开快照（跨设备），失败回退本地 store */
  const data = ref<ShareSnapshot>({
    share: sales.state.share,
    info: sales.state.info,
    coaches: sales.state.coaches,
    products: sales.state.products,
    cases: sales.state.cases
  })
  const loading = ref(true)
  /** 服务端明确判定失效（404：不存在 / 已停用）——此时不允许回退本地数据 */
  const revoked = ref(false)

  /**
   * 失效判定：**只**反映「链接本身不可用」，与「数据不全」严格区分。
   *
   * 三种失效情形（语义与修复前完全一致）：
   *  - revoked：服务端 404（不存在 / 已停用）
   *  - share.enabled === false：快照里明确停用
   *  - URL 里的码与快照里的权威码不一致（换了码或旧链接）
   *
   * 缺失字段**不**走这里：`share` 整键缺失只说明快照不完整，不该把用户引向
   * 「链接已失效、请联系门店重发」——那会掩盖真实的展示数据缺失，让门店白忙一次。
   * 所以 `share` 缺失时这里返回 false（照常渲染），由各块自行降级。
   */
  /**
   * 失效判定：**只**反映「链接本身不可用」，与「数据不全」严格区分。
   *
   * 三种失效情形（语义与修复前完全一致）：
   *  - revoked：服务端 404（不存在 / 已停用）
   *  - share.enabled === false：快照里明确停用
   *  - URL 里的码与快照里的权威码**确实不一致**（换了码或旧链接）
   *
   * 为什么缺键不判失效：真实后端只有在「存在 + 来源可信 + 启用中」时才返回 200
   * （PublicShareController::sales 三重校验后才 ok()），所以带 code 的 200 响应
   * 本身就意味着链接有效。此时若有键缺失，那是**快照不完整**，不是链接坏了 ——
   * 显示「链接已失效，请联系门店获取最新资料」会把用户引去重新发布，
   * 而真实问题只是某个字段没填，白跑一趟还掩盖了原因。
   *
   * 反向也要防：`share` 缺失时**不能**因为"取不到 code"就判成码不匹配
   * （这正是本页早先白屏的变体）—— 取不到依据时不做失效断言。
   */
  const invalid = computed(() => {
    // 草稿预览：渲染的是**本机草稿**，与线上发布态无关 —— 草稿没发布过也应当能看。
    // 分支入口有两道门（`?preview=1` + 本机持有同一个码），页面顶部另有醒目的预览角标，
    // 不存在「把链接已失效显示成正常」的风险。
    if (draftPreview.value) return false
    if (revoked.value) return true

    const share = data.value?.share
    // share 整键缺失/非对象：数据不全 → 照常渲染，由各块降级
    if (!share || typeof share !== 'object') return false
    // 明确停用：语义不变
    if (share.enabled === false) return true
    // 快照没带权威码：无从比对，按数据不全处理（不臆断为「失效」）
    if (typeof share.code !== 'string' || share.code === '') return false
    // 有权威码才做一致性比对（原有语义）
    return route.params.code !== share.code
  })

  /**
   * 字段级兜底：把可能整键缺失的容器归一成安全形状。
   *
   * 为什么需要：后端 `sanitizeSalesPayload()` **只保证 `share` 键存在**
   * （`$out['share'] = [...]` 无条件赋值），而 `info`/`products`/`coaches`/`cases`
   * 四键在「容器类型不符被丢弃」时会整个消失。这是 fail-closed 的必然代价，
   * 前端必须接住——否则缺一个键就整页白屏（客户只看到空白）。
   *
   * 注意本函数**不**给缺失的 `share` 编造 `{}`：空对象会让 `invalid` 里的
   * 码比对拿到 `undefined` 而误判成「已失效」，把「数据不全」显示成「链接失效」。
   * 缺失就保持缺失，由 `invalid` 按「无依据不判失效」处理。
   */
  const info = computed(() => data.value?.info ?? {})
  const coaches = computed(() => (Array.isArray(data.value?.coaches) ? data.value.coaches : []))

  /* ------------------------------------------- 模板 / 版式 / 主列板块集合 */

  /** 版式来源：预览看草稿，客户链接看 URL */
  const rawMode = computed<unknown>(() =>
    draftPreview.value ? draftState.value.displayMode : route.query.mode
  )
  const mode = computed(() => resolveSalesDisplayMode(rawMode.value, viewportWidth.value))
  const isWide = computed(() => mode.value === 'web')

  /**
   * 模板来源（经 `findSalesTemplate` **白名单查表**）：
   * 预览看草稿；客户链接看 `?t`；另外兼容「服务端快照带 templateId」——
   * 当前 `share` 容器白名单只有 `enabled|code|views`（`ShareController.php:50`），
   * 所以那条分支现在取不到值，属于**向前兼容**而不是当前通路。
   */
  const rawTemplateId = computed<string>(() => {
    if (draftPreview.value) return asString(draftState.value.templateId)
    const q = route.query.t
    const qv = Array.isArray(q) ? q[0] : q
    if (typeof qv === 'string' && qv) return qv
    const snap = data.value?.share as Record<string, unknown> | undefined
    return asString(snap?.templateId)
  })
  const template = computed(() => findSalesTemplate(rawTemplateId.value))
  const toneClass = computed(() => salesTemplateToneClass(template.value))
  const buttonClass = computed(() => salesTemplateButtonClass(template.value))
  /** 主视觉图（按版式选路）；空串 = 无图，模板色调渐变兜底 */
  const heroSrc = computed(() => pickSalesHeroPath(template.value, mode.value))

  /**
   * 「到店信息」是否有内容 —— 必须与管理端即时预览**同口径**，
   * 否则会出现「管理端说横屏移到侧栏、客户页却还在正文」的错位。
   */
  const hasLocation = computed(
    () =>
      Boolean(asString(info.value?.address).trim()) || Boolean(asString(info.value?.phone).trim())
  )
  /** 主列板块集合：横屏不含 location（它落位到侧栏，见模板里到店信息块的注释） */
  const mainSections = computed<SalesSectionKey[]>(() =>
    salesSections(mode.value, hasLocation.value).filter((k) => k !== 'location')
  )
  const locationInFlow = computed(() =>
    salesSections(mode.value, hasLocation.value).includes('location')
  )

  /* ------------------------------------------------------------ 产品与价目 */

  const productRows = computed(() =>
    (Array.isArray(data.value?.products) ? data.value.products : []).map((p) => ({
      id: p?.id,
      name: p?.name,
      desc: p?.desc,
      // 每个产品用自己的 cols / rows（「独立价目表」）；null → 展示「到店咨询」文案。
      // 注意 showPrice 是**内容层**开关，换模板不得改变它。
      table: productPriceTable(p)
    }))
  )

  /* -------------------------------------------------------------- 学员案例 */

  /**
   * 授权闸门（一麦独有，不得因「对齐小程序」删掉）。
   * 与发布 payload 里那处 `cases.filter(authorized)` 是**有意**的双保险：
   * 公开页与发布 payload 是两条独立路径，任何一条漏过滤都会泄露未授权案例。
   */
  const authedCases = computed<SalesCase[]>(() =>
    visibleCases(Array.isArray(data.value?.cases) ? data.value.cases : [])
  )

  const CASE_DIMENSIONS: { id: 'coach' | 'goal'; label: string }[] = [
    { id: 'coach', label: '按教练' },
    { id: 'goal', label: '按目标' }
  ]
  /** 默认维度 = 教练（源：`viewer.js:479` —— 小程序的真实筛选维度就是教练） */
  const caseDim = ref<'coach' | 'goal'>('coach')
  const activeKey = ref('')

  function switchDim(id: 'coach' | 'goal') {
    // 换维度必须清空筛选键：教练名与目标原文不同域，留着必然筛出一个空列表
    caseDim.value = id
    activeKey.value = ''
  }

  /** 教练筛选项：只列「确实带案例」的教练 */
  const coachChips = computed(() =>
    coaches.value
      .map((co) => ({
        key: asString(co?.name),
        label: asString(co?.name),
        count: countCasesByCoach(authedCases.value, asString(co?.name), coaches.value)
      }))
      .filter((c) => c.key !== '' && c.count > 0)
  )
  /** 目标筛选项：门店填写的目标原文（不引入预设枚举，§5.10） */
  const goalChips = computed(() => caseGoalChips(authedCases.value))
  const activeChips = computed(() =>
    caseDim.value === 'coach' ? coachChips.value : goalChips.value
  )
  const filteredCases = computed<SalesCase[]>(() =>
    caseDim.value === 'coach'
      ? filterCasesByCoach(authedCases.value, activeKey.value, coaches.value)
      : filterCasesByGoal(authedCases.value, activeKey.value)
  )

  /* 三个正文板块的可见性：都经过 `mainSections`（salesSections 契约），保证
     「板块集合随版式变」是单一来源，而不是散在模板里的 mode 判断。 */
  const showProducts = computed(
    () => mainSections.value.includes('products') && productRows.value.length > 0
  )
  const showCoaches = computed(
    () => mainSections.value.includes('coaches') && coaches.value.length > 0
  )
  const showCases = computed(
    () => mainSections.value.includes('cases') && authedCases.value.length > 0
  )

  /**
   * 把任意响应体归一成「形状安全」的快照（只按需补 info，不伪造 share）。
   *
   * `share` 保持原样（可能是 undefined）：`invalid` 判定依赖「有没有权威码」，
   * 伪造一个空对象会让码比对把 `undefined` 当成不一致 → 误显示失效页。
   */
  function normalizeSnapshot(raw: unknown): ShareSnapshot {
    const d = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>
    const info = d.info && typeof d.info === 'object' ? (d.info as Record<string, unknown>) : {}
    const share =
      d.share && typeof d.share === 'object' ? (d.share as Record<string, unknown>) : undefined
    return { ...d, share, info }
  }

  /**
   * 合规说明（S18）。
   *
   * 后端对「键缺失 / 空白（含全角空格、U+00A0）/ 非字符串」一律**不下发该键**，
   * 所以这里返回 null 表示「整块不渲染」——不要退化成空串去渲染一个空卡片：
   * 那会让客户以为门店漏填，而真实语义是「本店无需声明」。
   */
  const complianceNotice = computed(() => {
    const n = info.value?.complianceNotice
    if (typeof n !== 'string') return null
    const t = n.trim()
    return t === '' ? null : t
  })

  /** 电话链接：号码缺失/非字符串时返回 null（模板据此不渲染按钮） */
  const telHref = computed(() => {
    const phone = info.value?.phone
    const digits = typeof phone === 'string' ? phone.replace(/[^\d+]/g, '') : ''
    return digits ? `tel:${digits}` : null
  })

  /** 地址可复制：空地址时「复制地址」按钮无意义，不渲染 */
  const copyableAddress = computed(() => {
    const address = info.value?.address
    return typeof address === 'string' && address.trim() !== ''
  })

  function coachName(c: { coachId?: number | '' }): string {
    if (c?.coachId === '' || c?.coachId == null) return ''
    return coaches.value.find((x) => x?.id === c.coachId)?.name ?? ''
  }

  async function copyAddress() {
    const address = info.value?.address
    if (typeof address !== 'string' || address.trim() === '') {
      ElMessage.warning('门店地址暂未提供')
      return
    }
    try {
      await navigator.clipboard.writeText(address)
      ElMessage.success('地址已复制')
    } catch {
      ElMessage.warning(address)
    }
  }

  /** 草稿快照 → 与本页享用的渲染数据源同形（案例只取已授权，口径不因预览而放宽） */
  function applyDraftSnapshot() {
    const d = draftState.value
    data.value = normalizeSnapshot({
      share: d.share,
      info: d.info,
      coaches: d.coaches,
      products: d.products,
      cases: visibleCases(Array.isArray(d.cases) ? (d.cases as SalesCase[]) : [])
    })
  }

  /**
   * 数据源解析。watch 源是**两个**而不是只有 code：
   *   · `route.params.code` —— 换了分享码；
   *   · `draftPreview`（= `?preview=1` + 本机同码）—— 数据源选择器本身。
   * 只 watch code 时，同一会话内手动把 `#/s/CODE` 改成 `#/s/CODE?preview=1`（路由不变、无重载）
   * 不会重跑本段：会出现「角标说在预览、页面却还是服务端快照」（反向同理，退出预览后
   * 仍拿本机草稿当观测结果展示）。加上第二个源后，两条方向都会重新解析。
   */
  watch(
    [() => route.params.code, draftPreview],
    async ([codeRaw]) => {
      const code = String(codeRaw ?? '')
      loading.value = true
      revoked.value = false
      // 草稿预览：**不调公开接口**（零副作用 + 免刷新跟随草稿），直接渲染本机草稿
      if (draftPreview.value) {
        liveDraft.value = readPersistedSalesDraft()
        applyDraftSnapshot()
        loading.value = false
        return
      }
      try {
        const base = (import.meta.env.VITE_API_BASE as string) || '/api'
        const resp = await axios.get(
          `${base.replace(/\/$/, '')}/public/sales/${encodeURIComponent(code)}`
        )
        // 归一成形状安全的快照：响应体异常时 `resp.data?.data` 可能为 undefined，
        // 一旦赋进去，后面所有 `data.value.*` 都会连锁抛错（整页白屏）。
        data.value = normalizeSnapshot(resp.data?.data)
      } catch (e) {
        // 服务端已明确判定该分享不存在/已停用：直接展示失效页，不回退到本地演示数据 ——
        // 否则停用后在同浏览器打开仍能看到内容，与服务端的停用语义矛盾。
        if (axios.isAxiosError(e) && e.response?.status === 404) {
          revoked.value = true
          return
        }
        // 其他失败（演示模式 / 网络不可达）：回退本地（同浏览器）。
        // 本地回退同样只取已授权案例，不因为「没连上服务端」就放宽口径。
        data.value = normalizeSnapshot({
          share: sales.state.share,
          info: sales.state.info,
          coaches: sales.state.coaches,
          products: sales.state.products,
          cases: sales.state.cases.filter((c) => c?.authorized)
        })
      } finally {
        loading.value = false
      }
    },
    { immediate: true }
  )

  /** 预览模式：管理端改动随 localStorage 写回，这里即时重渲染（客户链接不走这条） */
  watch(liveDraft, () => {
    if (draftPreview.value) applyDraftSnapshot()
  })

  onMounted(() => {
    onViewportResize()
    window.addEventListener('resize', onViewportResize)
    window.addEventListener('storage', onStorage)
  })

  onUnmounted(() => {
    window.removeEventListener('resize', onViewportResize)
    window.removeEventListener('storage', onStorage)
  })

  /**
   * 访问量计数：链接确认为有效后再登记（加载完成前 loading 为 true，不会登记）。
   *
   * 草稿预览**必须排除**：预览是零副作用的本地查看（对齐源：小程序 `previewReady`
   * 不触达任何云函数），若预览也计数，店长每次改完看一眼都会把访问量刷上去，
   * 这个数字就不再反映真实客户行为。
   */
  watch(
    [loading, invalid, draftPreview],
    () => {
      if (!loading.value && !invalid.value && !draftPreview.value) sales.registerView()
    },
    { immediate: true }
  )
</script>

<style scoped lang="scss">
  @use '../yimai/sales/template-visuals.scss' as tv;

  /* 页面容器：竖屏与横屏共用底色，只有宽度/栏数不同 */
  .page {
    min-height: 100vh;
    padding-bottom: 40px;
    background: #faf7f2;
  }

  /* 草稿预览角标：必须一眼可辨 —— 它意味着「本页不是客户看到的页面」 */
  .preview-bar {
    position: sticky;
    top: 0;
    z-index: 10;
    padding: 8px 12px;
    font-size: 12px;
    line-height: 1.45;
    color: #8a6d3b;
    text-align: center;
    background: #fdf3e2;
    border-bottom: 1px solid #f0dcb8;
  }

  /* 品牌头：底色由模板色调 token 覆盖（下方 @include），这里是兜底色 */
  .hero {
    padding: 32px 20px 26px;
    color: #fff;
    text-align: center;
    background: linear-gradient(135deg, #2f7d5d, #1d5c43);
  }

  .hero-web {
    padding: 44px 24px 36px;
  }

  /*
    主视觉图（模板 hero*Url 非空时才有这个元素）。
    只做「图 + 文字块」的上下排布：不把图当背景，避免照片压住文字导致对比度不足。
  */
  .hero-img {
    display: block;
    width: 100%;
    max-height: 220px;
    margin: 0 auto 14px;
    object-fit: cover;
    border-radius: 10px;
  }

  .hero-title {
    font-size: 20px;
    font-weight: 600;
    letter-spacing: 0.02em;
  }

  .hero-web .hero-title {
    font-size: 28px;
  }

  .hero-line {
    margin-top: 4px;
    font-size: 13px;
    opacity: 0.85;
  }

  .hero-slogan {
    margin-top: 16px;
    font-size: 18px;
    font-weight: 500;
  }

  .hero-intro {
    max-width: 620px;
    margin: 8px auto 0;
    font-size: 12px;
    line-height: 1.6;
    opacity: 0.72;
  }

  /*
    双端版式：同一份内容两种呈现。
      · 竖屏（默认 / 窄视口）：单列 420px，正文顺序 = 产品 → 教练 → 案例 → 到店信息
      · 横屏（?mode=web 或宽视口）：主列 + 320px 侧栏，到店信息落侧栏
    两种版式都不隐藏任何数据，只是栏位不同。
  */
  .body {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 18px;
    max-width: 420px;
    padding: 0 16px;
    margin: 18px auto 0;
  }

  .body.is-wide {
    grid-template-columns: minmax(0, 1fr) 320px;
    gap: 22px;
    max-width: 1080px;
    padding: 0 24px;
    margin-top: 24px;
  }

  .col {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  /* 到店信息落位：竖屏在正文末节（loc-flow），横屏进侧栏（loc-rail）。
     `.loc-rail` 的定位只在 `.is-wide` 下生效 —— 这样「竖屏但没填地址/电话」
    （salesSections 此时不含 location）不会把一个单列网格撑出第二列。 */
  .loc {
    min-width: 0;
  }

  .body.is-wide .loc-rail {
    grid-row: 1;
    grid-column: 2;
    position: sticky;
    top: 16px;
    align-self: start;
  }

  .sec {
    margin-bottom: 18px;
  }

  .sec-title {
    margin-bottom: 10px;
    padding-left: 8px;
    font-size: 15px;
    font-weight: 600;
    color: #1d5c43;
    border-left: 3px solid #2f7d5d;
  }

  .card {
    padding: 12px 14px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgb(31 92 66 / 6%);
  }

  /* 价目表：宽表在窄屏横向滚动，**不裁切**（裁切会让客户看不到后面的列） */
  .table-wrap {
    margin-top: 12px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }

  .price-table {
    width: 100%;
    min-width: 320px;
    font-size: 12px;
    border-collapse: collapse;
  }

  .price-table thead tr {
    background: #eef5f0;
  }

  .coach-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 12px;
  }

  .body.is-wide .coach-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .tag {
    padding: 1px 8px;
    font-size: 11px;
    color: #2f7d5d;
    cursor: default;
    background: #eef5f0;
    border-radius: 999px;
  }

  /* 案例筛选：flex-wrap 而不是横向滚动 —— 换行不会把筛选项裁在视口外 */
  .filters {
    margin-bottom: 10px;
  }

  .chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
  }

  .chip {
    display: inline-flex;
    gap: 4px;
    align-items: center;
    /* 移动端触控目标 ≥44px（客户多是单手在微信里点） */
    min-height: 44px;
    padding: 0 12px;
    font-size: 12px;
    color: #1d5c43;
    cursor: pointer;
    background: #fff;
    border: 1px solid #d9e6df;
    border-radius: 999px;
  }

  .chip.is-active {
    color: #fff;
    background: #2f7d5d;
    border-color: #2f7d5d;
  }

  .chip-dim {
    color: #5c6b64;
    background: #f1f4f2;
    border-color: transparent;
  }

  .chip-dim.is-active {
    color: #1d5c43;
    background: #dfeee6;
    border-color: #b9d6c7;
  }

  .chip-n {
    font-size: 11px;
    opacity: 0.7;
  }

  .coach-avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 46px;
    height: 46px;
    font-size: 18px;
    font-weight: 600;
    color: #fff;
    background: linear-gradient(135deg, #2f7d5d, #55a381);
    border-radius: 50%;
  }

  /* CTA：min-height 44px 保证移动端点得中（原来是 padding 撑出的 ~42px） */
  .cta {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    padding: 0 12px;
    font-size: 14px;
    font-weight: 600;
    color: #fff;
    text-align: center;
    text-decoration: none;
    background: #2f7d5d;
    border: 0;
    border-radius: 999px;

    &.secondary {
      color: #2f7d5d;
      background: #fff;
      border: 1px solid #2f7d5d;
    }
  }

  /* 模板 token（色调 / 按钮风格）：放在最后，才能覆盖上面的兜底色与形状 */
  @include tv.sales-template-visuals;
</style>
