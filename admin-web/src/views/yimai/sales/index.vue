<template>
  <div class="list-page">
    <!-- 分享控制 -->
    <ElCard shadow="never" class="mb-4">
      <div class="tap flex flex-wrap items-center gap-4">
        <div>
          <div class="font-500 mb-1">分享页</div>
          <span class="text-xs text-gray-400"
            >{{ currentModeName }}（{{ currentModeDesc }}）· 客户在微信内打开 ·
            草稿不影响已发布内容（阶段1引入发布版本隔离）</span
          >
        </div>
        <div class="flex-1" />
        <ElTag size="small" :type="sales.state.share.enabled ? 'success' : 'danger'">
          {{ sales.state.share.enabled ? '分享中' : '已停用' }}
        </ElTag>
        <!-- 没读到服务端权威状态时明确标注：此时开关只是本地草稿，不代表线上 -->
        <ElTag v-if="USE_BACKEND && !shareReady" size="small" type="warning" effect="plain">
          未同步服务端
        </ElTag>
        <!-- 启用中但码已失效（升级后存量链接）：界面不能显示「分享中」而让客户看到 404 -->
        <ElTag v-if="needsRepublish" size="small" type="danger" effect="plain">
          分享码已失效，请重新开启分享
        </ElTag>
        <!-- 展示的是他人记录：明确标注来源，且禁止就地开关 -->
        <ElTag v-if="viewingOtherName !== null" size="small" type="info" effect="plain">
          这是 {{ viewingOtherName }} 的分享（不可在此开关）
        </ElTag>
        <span class="text-xs text-gray-400">访问量 {{ sales.state.share.views }}</span>
        <ElSwitch
          :model-value="sales.state.share.enabled"
          :loading="toggling"
          :disabled="viewingOtherName !== null"
          active-text="开启"
          inactive-text="停用"
          @change="(v: string | number | boolean) => onShareToggle(Boolean(v))"
        />
      </div>

      <!--
        操作区与开关分行：移动端（店长用手机改价目）挤在一行会把按钮压到 40px 以下，
        触控目标不达标。`.tap` 统一把 Element 控件撑到 ≥44px 可点高度。
      -->
      <div class="tap mt-3 flex flex-wrap items-center gap-2">
        <ElButton
          type="primary"
          :disabled="viewingOtherName !== null"
          title="在未发布状态下预览当前草稿（不落库、不计数、不影响线上）"
          @click="openDraftPreview"
          >草稿预览（未发布）</ElButton
        >
        <ElButton :disabled="viewingOtherName !== null" @click="openCustomerPage"
          >打开客户链接</ElButton
        >
        <ElButton :disabled="viewingOtherName !== null" @click="copyCustomerLink"
          >复制客户链接</ElButton
        >
        <span class="text-xs text-gray-400"
          >草稿预览只在本浏览器渲染：不发布、不改开关、不计访问量；客户链接打开的是已发布内容</span
        >
      </div>
    </ElCard>

    <!--
      视觉模板与版式（源：pages/sales-tool/home.wxml:141-142 / home.js:374-383）。
      红线：模板**只控制视觉风格，展示文字全部来自门店填写** ——
      切换不得改动 info / products / coaches / cases 里任何一个值，也不覆盖 showPrice。
    -->
    <ElCard shadow="never" class="mb-4">
      <div class="flex flex-wrap items-start gap-x-8 gap-y-4">
        <div class="min-w-64 flex-1">
          <div class="font-500 mb-1">视觉模板</div>
          <div class="text-xs text-gray-400 mb-2"
            >模板只控制视觉风格，展示文字全部来自门店填写（换模板不改任何已填内容）</div
          >
          <div class="tpl-grid" role="radiogroup" aria-label="视觉模板">
            <button
              v-for="t in SALES_TEMPLATES"
              :key="t.id"
              type="button"
              class="tpl-card"
              :class="{ 'is-active': t.id === sales.state.templateId }"
              role="radio"
              :aria-checked="t.id === sales.state.templateId"
              :aria-label="`${t.name}（${t.desc}）`"
              @click="sales.setTemplateId(t.id)"
            >
              <span class="tpl-swatch" :class="salesTemplateToneClass(t)" />
              <span class="tpl-name">{{ t.name }}</span>
              <span class="tpl-desc">{{ t.desc }}</span>
            </button>
          </div>
          <div class="mt-2 text-xs text-gray-400">
            <span v-for="g in SALES_TEMPLATE_GROUPS" :key="g.id" class="mr-3"
              >{{ g.label }}：{{ g.templateIds.length }} 套（{{ g.desc }}）</span
            >
          </div>
        </div>

        <div class="min-w-56">
          <div class="font-500 mb-1">双端版式</div>
          <div class="text-xs text-gray-400 mb-2">同一份内容两种呈现，不改数据</div>
          <div class="tap">
            <ElRadioGroup
              :model-value="sales.state.displayMode"
              @update:model-value="(v: string | number | boolean | undefined) => onModeChange(v)"
            >
              <ElRadioButton v-for="m in SALES_DISPLAY_MODES" :key="m.id" :value="m.id">
                {{ m.name }} · {{ m.desc }}
              </ElRadioButton>
            </ElRadioGroup>
          </div>
        </div>
      </div>

      <!-- 即时预览：模板 / 版式一改这里立刻变，不用开新标签页 -->
      <div class="mt-4 flex flex-wrap items-start gap-4">
        <div class="frame" :class="isWebMode ? 'frame-web' : 'frame-h5'">
          <div class="frame-hero" :class="currentToneClass">
            <img v-if="currentHeroSrc" class="frame-img" :src="currentHeroSrc" alt="" />
            <div class="frame-title">{{ sales.state.info.name || '一麦瑜伽' }}</div>
            <div class="frame-sub">{{
              sales.state.info.slogan || '（Slogan 在「门店信息」里填写）'
            }}</div>
          </div>
          <div class="frame-body">
            <div
              v-for="k in previewSectionKeys"
              :key="k"
              class="frame-sec"
              :class="{ 'is-muted': previewPlacements[k].muted }"
            >
              {{ SECTION_LABELS[k] }}
              <span v-if="k === 'products'" class="frame-chip">独立价目表</span>
              <span v-else-if="k === 'cases'" class="frame-chip">可筛选</span>
              <span v-if="previewPlacements[k].label" class="frame-chip is-off">{{
                previewPlacements[k].label
              }}</span>
            </div>
            <div class="frame-cta" :class="currentButtonClass">电话咨询</div>
          </div>
        </div>
        <div class="max-w-80 text-xs text-gray-400 leading-5">
          <p class="text-gray-500">即时预览：{{ currentModeName }} · {{ currentTemplate.name }}</p>
          <p class="mt-1"
            >· 模板与版式随「复制客户链接」一起传递，客户打开即生效（视觉不需要重新发布）。</p
          >
          <p>· 门店<strong>文字内容</strong>改动仍需重新开启一次分享才会同步到线上快照。</p>
          <p>· 已发出的旧链接保持原样；重新复制链接才会带上当前模板与版式。</p>
        </div>
      </div>
    </ElCard>

    <ElCard shadow="never">
      <ElTabs v-model="tab">
        <ElTabPane name="basic" label="门店信息"><Basic /></ElTabPane>
        <ElTabPane name="products" label="产品与价目"><Products /></ElTabPane>
        <ElTabPane name="coaches" label="推荐教练"><Coaches /></ElTabPane>
        <ElTabPane name="cases" label="学员案例"><Cases /></ElTabPane>
        <!--
          归属异常清单：仅超管（后端 /shares/orphans 用 requireSuper）。
          为什么需要这个入口：归属写入只认账号 id、并把「同名歧义」挡在写之外（必要的安全约束），
          代价是姓名对不上账号 / id 悬挂的历史行变成「无人在线可管」的记录 ——
          若它 enabled=true，链接仍能被客户打开，而原主人既看不到也停不掉。
          没有这个 UI 之前，超管只能手工调 API 才能处置这类活链接。
        -->
        <ElTabPane v-if="isSuper" name="orphans" label="归属异常">
          <Orphans />
        </ElTabPane>
      </ElTabs>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import Basic from './modules/basic.vue'
  import Products from './modules/products.vue'
  import Coaches from './modules/coaches.vue'
  import Cases from './modules/cases.vue'
  import Orphans from './modules/orphans.vue'
  import {
    useSalesStore,
    SALES_TEMPLATES,
    SALES_TEMPLATE_GROUPS,
    SALES_DISPLAY_MODES,
    findSalesTemplate,
    salesTemplateToneClass,
    salesTemplateButtonClass,
    salesDisplayModeName,
    salesSections,
    pickSalesHeroPath,
    isSalesDisplayMode,
    buildSalesShareHash,
    type SalesSectionKey
  } from '@/store/modules/sales'
  import { useUserStore } from '@/store/modules/user'
  import { publishShare, disableShare, getCurrentShare } from '@/api/yimai'
  import { USE_BACKEND } from '@/api/backend'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'YimaiSales' })

  const sales = useSalesStore()
  const tab = ref('basic')

  /** 超管判定：复用全站既有范式（roles 里含 R_SUPER，见 views/yimai/tasks/index.vue:228） */
  const userStore = useUserStore()
  const roles = computed(() => userStore.getUserInfo.roles ?? [])
  const isSuper = computed(() => roles.value.includes('R_SUPER'))
  /** 开关请求进行中，避免连点造成「开了又停」的竞态 */
  const toggling = ref(false)
  /** 是否已成功读到服务端权威状态（false 表示界面上的开关只是本地值，不可信） */
  const shareReady = ref(false)
  /**
   * 服务端当前分享码是否已失效（启用中但来源不可信）。
   *
   * 升级后存量链接会被标为 legacy：`enabled` 仍是 true，但公开接口一律 404。
   * 没有这个标志时界面会显示「分享中」而客户打开是 404。
   */
  const needsRepublish = ref(false)
  /**
   * 若当前展示的不是本人记录（超管显式查看他人），这里是被查看人姓名。
   *
   * 超管页面**默认不会再回填他人链接**（服务端只回本人）；只有超管显式查询他人时
   * 才会出现该值，此时必须标注来源，且不得把对方的码写进本地 store。
   */
  const viewingOtherName = ref<string | null>(null)

  /**
   * 打开页面时以服务端为准回填分享状态。
   *
   * 分享码由服务端签发后，本地持久化的那份可能已经过期（换发过码、或仍是历史
   * 可猜常量），页面必须拿到权威 token 才能给出正确的分享链接。
   * 拉取失败时给出可读提示而不是静默 —— 静默会让界面显示本地旧状态，
   * 与线上事实不一致（正是本次修复要消灭的那类「界面说停用、线上还开着」）。
   */
  async function syncShareState() {
    if (!USE_BACKEND) return
    try {
      const cur = await getCurrentShare('sales')
      sales.setShareEnabled(cur.enabled)
      needsRepublish.value = Boolean(cur.needsRepublish)
      viewingOtherName.value = cur.viewingOther ? (cur.ownerName ?? '') : null
      // 只有自己的码才写入本地 store：超管查看他人时若落本地，
      // 预览按钮会发出别人的链接、开关也会显示成「我的分享」。
      if (!cur.viewingOther && cur.enabled && cur.token) sales.setShareCode(cur.token)
      shareReady.value = true
      if (cur.needsRepublish) {
        ElMessage.warning('当前分享码已失效（升级后需重新发布），请重新开启一次分享生成新链接')
      }
    } catch (e) {
      shareReady.value = false
      const status = (e as { response?: { status?: number } })?.response?.status
      if (status === 403) {
        ElMessage.warning('当前账号无权管理对外分享（仅超管与店长），页面显示的是本地草稿')
      } else if (status === 503) {
        ElMessage.warning('服务端结构升级尚未完成，分享开关暂时不可用，请稍后重试')
      }
    }
  }

  /**
   * 开启/停用分享，前后端状态必须一致。
   *
   * - 开启：把当前工作台内容发布为服务端快照（H5 跨设备可访问），并采用
   *   服务端签发的分享码 —— 公开 URL 不再由客户端可猜常量决定；
   * - 停用：调用服务端把记录置 enabled=false（公开接口据此返回 404）。
   *   原先停用只改本地状态就 return，服务端记录原样保留，旧链接依然能打开。
   * 任一调用失败都回退本地开关，避免「界面显示已停用、线上其实还能访问」。
   */
  async function onShareToggle(enabled: boolean) {
    if (!USE_BACKEND) {
      sales.setShareEnabled(enabled)
      return
    }
    if (toggling.value) return
    // 展示的是他人记录时不允许就地开关：这会让人以为在操作自己的分享。
    // 服务端也已收口（不带目标的 disable 只影响本人），前端必须在动手前就拦住。
    if (viewingOtherName.value !== null) {
      ElMessage.warning(
        `当前展示的是 ${viewingOtherName.value} 的分享，请勿在此开关；如需处置请用运维入口指定链接`
      )
      return
    }
    toggling.value = true
    try {
      if (enabled) {
        const res = await publishShare('sales', sales.state.share.code, {
          share: sales.state.share,
          info: sales.state.info,
          products: sales.state.products,
          coaches: sales.state.coaches,
          // 提交全量案例，由服务端按 authorized 过滤（服务端是过滤的权威方）
          cases: sales.state.cases
        })
        if (res?.token) sales.setShareCode(res.token)
        sales.setShareEnabled(true)
        shareReady.value = true
        needsRepublish.value = false
        ElMessage.success('已发布到线上，客户可在微信中打开')
      } else {
        // 不带目标：服务端只停用本人记录（超管也不会一次停全库）
        await disableShare('sales')
        sales.setShareEnabled(false)
        needsRepublish.value = false
        ElMessage.success('已停用分享，旧链接打开会提示已失效')
      }
    } catch (e) {
      // 失败必须回退开关：否则界面显示已停用、线上其实还能访问
      sales.setShareEnabled(!enabled)
      const status = (e as { response?: { status?: number } })?.response?.status
      const serverMsg = (e as { response?: { data?: { emsg?: string } } })?.response?.data?.emsg
      const tip =
        status === 403
          ? '无权管理对外分享（仅超管与店长）'
          : status === 503
            ? '服务端结构升级尚未完成，暂时无法停用，请稍后重试'
            : status === 422 && serverMsg
              ? serverMsg
              : String(e).slice(0, 80)
      ElMessage.error(`${enabled ? '线上发布' : '停用'}失败：${tip}`)
    } finally {
      toggling.value = false
    }
  }

  onMounted(syncShareState)

  /* ---------------------------------------------------- 模板 / 版式 / 预览 */

  const currentTemplate = computed(() => findSalesTemplate(sales.state.templateId))
  const currentToneClass = computed(() => salesTemplateToneClass(currentTemplate.value))
  const currentButtonClass = computed(() => salesTemplateButtonClass(currentTemplate.value))
  const isWebMode = computed(() => sales.state.displayMode === 'web')
  const currentModeName = computed(() => salesDisplayModeName(sales.state.displayMode))
  const currentModeDesc = computed(
    () => SALES_DISPLAY_MODES.find((m) => m.id === sales.state.displayMode)?.desc ?? ''
  )
  /** 到店信息是否有可展示内容 —— 与分享页 `salesSections(mode, hasLocation)` 同口径 */
  const hasLocation = computed(
    () =>
      Boolean((sales.state.info.address ?? '').trim()) ||
      Boolean((sales.state.info.phone ?? '').trim())
  )
  /** 即时预览小样的主视觉图（第二个消费点；空串 = 无图，走色调渐变） */
  const currentHeroSrc = computed(() =>
    pickSalesHeroPath(currentTemplate.value, sales.state.displayMode)
  )

  /**
   * 每个板块在小样里的落位**原因**（不是「在不在主列」一个布尔）——R2：
   * 原先只看「不在主列」就显示「横屏移至侧栏」，于是「H5 + 未填到店信息」
   * （location 因**无内容**而不在主列）也挂上了横屏标签，标签与真实原因不符。
   * 现在按原因分档：rail=版式导致 / empty=内容缺失导致 / flow=就在正文里（不挂标签）。
   * 主列集合仍只认 `salesSections()` 这一处契约（契约改了，小样自动跟随）。
   */
  const previewPlacements = computed<Record<SalesSectionKey, { muted: boolean; label: string }>>(
    () => {
      const inMain = new Set(salesSections(sales.state.displayMode, hasLocation.value))
      const build = (k: SalesSectionKey) => {
        if (inMain.has(k)) return { muted: false, label: '' }
        if (k !== 'location') return { muted: true, label: '' }
        // 到店信息不在主列：区分「版式挪到侧栏」与「根本没内容可显示」两种原因
        return isWebMode.value
          ? { muted: true, label: '横屏移至侧栏' }
          : { muted: true, label: '未填到店信息' }
      }
      return {
        products: build('products'),
        coaches: build('coaches'),
        cases: build('cases'),
        location: build('location')
      }
    }
  )

  const SECTION_LABELS: Record<SalesSectionKey, string> = {
    products: '产品与价目',
    coaches: '推荐教练',
    cases: '学员案例',
    location: '到店信息'
  }
  /** 即时预览固定展示 4 个板块并标注哪些在横屏被移到侧栏，便于店长看清版式差异 */
  const previewSectionKeys: SalesSectionKey[] = ['products', 'coaches', 'cases', 'location']

  function onModeChange(v: unknown) {
    if (isSalesDisplayMode(v)) sales.setDisplayMode(v)
  }

  /** 分享页基地址（hash 路由：origin + pathname + hash） */
  function shareBaseUrl() {
    return `${window.location.origin}${window.location.pathname}`
  }

  /**
   * 客户链接：带当前模板与版式。
   *
   * 默认值（h5 + 默认模板）**不写进 URL**，所以默认状态下复制出来的就是
   * `#/s/{code}` —— 与升级前逐字节相同（「现有链接行为不变」的结构性保证）。
   */
  function customerHash() {
    return buildSalesShareHash({
      code: sales.state.share.code,
      mode: sales.state.displayMode,
      templateId: sales.state.templateId
    })
  }

  function openCustomerPage() {
    window.open(`${shareBaseUrl()}${customerHash()}`, '_blank')
  }

  /**
   * 草稿预览：**零副作用**（对齐源：`pages/sales-tool/home.js:390-398` 的 previewReady）。
   *
   * 只 `window.open` 一个带 `?preview=1` 的链接 —— 不调 publishShare、不改 share.enabled、
   * 不写「发布」审计、不计访问量。分享页侧还有第二道门（`canUseDraftPreview`）：
   * 只有本浏览器持有同一个码时才走草稿分支，避免任何访客加个参数就看到本机演示数据。
   */
  function openDraftPreview() {
    const hash = buildSalesShareHash({
      code: sales.state.share.code,
      mode: sales.state.displayMode,
      templateId: sales.state.templateId,
      preview: true
    })
    window.open(`${shareBaseUrl()}${hash}`, '_blank')
  }

  async function copyCustomerLink() {
    const url = `${shareBaseUrl()}${customerHash()}`
    try {
      await navigator.clipboard.writeText(url)
      ElMessage.success('客户链接已复制（含当前模板与版式）')
    } catch {
      // 无剪贴板权限（非 https / 旧 WebView）时把链接显示出来，让店长手动复制
      ElMessage.warning(url)
    }
  }
</script>

<style scoped lang="scss">
  @use './template-visuals.scss' as tv;

  /* 移动端触控目标 ≥44px：Element 默认控件高度 32px，店长用手机改价目时点不中 */
  .tap {
    :deep(.el-button) {
      min-height: 44px;
    }

    :deep(.el-switch) {
      min-height: 44px;
    }

    :deep(.el-radio-button__inner) {
      display: flex;
      align-items: center;
      min-height: 44px;
    }
  }

  .tpl-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 8px;
  }

  .tpl-card {
    display: flex;
    flex-direction: column;
    gap: 2px;
    align-items: flex-start;
    min-height: 44px;
    padding: 8px 10px;
    text-align: left;
    cursor: pointer;
    background: #fff;
    border: 1px solid var(--el-border-color);
    border-radius: 10px;
    transition: border-color 0.2s;

    &.is-active {
      border-color: var(--el-color-primary);
      box-shadow: 0 0 0 1px var(--el-color-primary) inset;
    }
  }

  .tpl-swatch {
    width: 100%;
    height: 14px;
    border-radius: 4px;
  }

  .tpl-name {
    font-size: 13px;
    font-weight: 500;
  }

  .tpl-desc {
    font-size: 11px;
    color: var(--el-text-color-secondary);
  }

  /* 即时预览小样：手机框 / 宽屏框两种，只做版式示意（内容来自 store 的只读快照） */
  .frame {
    overflow: hidden;
    background: #faf7f2;
    border: 1px solid var(--el-border-color);
    border-radius: 12px;
    box-shadow: 0 2px 10px rgb(31 92 66 / 6%);
  }

  .frame-h5 {
    width: 190px;
  }

  .frame-web {
    width: 360px;
  }

  .frame-hero {
    padding: 12px 10px;
    color: #fff;
  }

  .frame-web .frame-hero {
    padding: 14px 16px;
  }

  /* 主视觉图（模板 hero*Url 非空时才有）——与分享页同款「图在上、文字在下」排布 */
  .frame-img {
    display: block;
    width: 100%;
    max-height: 72px;
    margin-bottom: 8px;
    object-fit: cover;
    border-radius: 6px;
  }

  .frame-title {
    font-size: 13px;
    font-weight: 600;
  }

  .frame-sub {
    margin-top: 2px;
    font-size: 10px;
    opacity: 0.85;
  }

  .frame-body {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 10px;
  }

  .frame-web .frame-body {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .frame-sec {
    display: flex;
    gap: 6px;
    align-items: center;
    padding: 6px 8px;
    font-size: 10px;
    background: #fff;
    border-radius: 8px;

    &.is-muted {
      opacity: 0.55;
    }
  }

  .frame-chip {
    padding: 0 5px;
    font-size: 9px;
    color: #2f7d5d;
    background: #eef5f0;
    border-radius: 999px;
  }

  .frame-chip.is-off {
    color: #8a6d3b;
    background: #fdf3e2;
  }

  .frame-cta {
    padding: 7px 0;
    font-size: 11px;
    font-weight: 600;
    color: #fff;
    text-align: center;
    background: #2f7d5d;
  }

  @include tv.sales-template-visuals;
</style>
