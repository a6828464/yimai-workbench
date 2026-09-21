<template>
  <div class="min-h-100vh bg-[#faf7f2] pb-10">
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
      <!-- 品牌头：info 整键可能缺失（见 script 里的降级说明），逐行判空后再渲染 -->
      <header class="px-5 pt-8 pb-6 text-center text-white" style="background: linear-gradient(135deg, #2f7d5d, #1d5c43)">
        <!-- 标题是版式的视觉主体，缺 name 时用品牌名兜底，而不是留一条空白绿带 -->
        <h1 class="text-xl font-600 tracking-wide">{{ info.name || '一麦瑜伽' }}</h1>
        <p v-if="info.industry" class="mt-1 text-sm opacity-80">{{ info.industry }}</p>
        <p v-if="info.slogan" class="mt-4 text-lg font-500">「 {{ info.slogan }} 」</p>
        <p v-if="info.intro" class="mt-2 text-xs opacity-70 max-w-70 mx-auto">{{ info.intro }}</p>
      </header>

      <main class="max-w-100 mx-auto px-4">
        <!-- 产品与价目：整块无数据时连标题一起隐藏，避免留下空白小节 -->
        <section v-if="priceProducts.length" class="mt-5">
          <h2 class="sec-title">产品与价目</h2>
          <div v-for="p in priceProducts" :key="p.id" class="card mb-3">
            <div class="flex items-center justify-between">
              <h3 class="font-600">{{ p.name }}</h3>
            </div>
            <p class="text-xs text-gray-500 mt-1 leading-5">{{ p.desc }}</p>
            <table v-if="p.showPrice" class="w-full mt-3 text-xs border-collapse">
              <thead>
                <tr style="background: #eef5f0">
                  <th v-for="c in p.cols" :key="c" class="border border-gray-200 px-2 py-1.5 font-500 text-left">{{ c }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, ri) in p.rows" :key="ri">
                  <td v-for="(cell, ci) in row" :key="ci" class="border border-gray-200 px-2 py-1.5" :class="{ 'font-600 text-green-800': String(cell).startsWith('¥') }">{{ cell }}</td>
                </tr>
              </tbody>
            </table>
            <p v-else class="mt-2 text-xs text-gray-400">价目详情请到店咨询</p>
          </div>
        </section>

        <!-- 推荐教练：同理，无教练时隐藏整节 -->
        <section v-if="coaches.length" class="mt-5">
          <h2 class="sec-title">推荐教练</h2>
          <div v-for="c in coaches" :key="c.id" class="card mb-3 flex gap-3 items-start">
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
        </section>

        <!-- 学员案例（仅已授权） -->
        <section v-if="authedCases.length" class="mt-5">
          <h2 class="sec-title">学员案例</h2>
          <p class="text-xs text-gray-400 mb-2">以下案例由门店确认已取得会员授权 · 展示不含面部信息</p>
          <div v-for="c in authedCases" :key="c.id" class="card mb-3">
            <div class="text-xs text-gray-400 mb-1">目标</div>
            <h3 class="font-600">{{ c.goal }}</h3>
            <p class="text-xs text-gray-500 mt-1 leading-5">{{ c.desc }}</p>
            <div v-if="coachName(c)" class="mt-2 text-xs"><ElTag size="small" effect="plain">指导教练：{{ coachName(c) }}</ElTag></div>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <ElTag v-for="(s, si) in c.stages || []" :key="si" size="small" :effect="si === 0 ? 'plain' : 'dark'" type="success">
                {{ si === 0 ? '初始' : s.duration || `阶段${si}` }}
              </ElTag>
            </div>
          </div>
        </section>

        <!-- 到店信息：info 可能整键缺失，缺地址/电话时隐藏对应行与按钮 -->
        <section class="mt-5">
          <h2 class="sec-title">到店信息</h2>
          <div v-if="info.address || info.phone" class="card space-y-2 text-sm">
            <p v-if="info.address">📍 {{ info.address }}</p>
            <p v-if="info.phone">📞 {{ info.phone }}</p>
          </div>
          <div v-else class="card text-sm text-gray-400">门店地址与电话暂未提供，可直接到店咨询</div>
          <!-- 电话/地址为空时不渲染对应按钮：空的 tel: 链接点下去会误拨、复制空串也无意义 -->
          <div v-if="telHref || copyableAddress" class="mt-4 grid grid-cols-2 gap-3">
            <a v-if="telHref" class="cta" :href="telHref">电话咨询</a>
            <button v-if="copyableAddress" class="cta secondary" @click="copyAddress">复制地址</button>
          </div>
          <p class="mt-4 text-center text-xs text-gray-400">© 一麦瑜伽 · 双店运营</p>
        </section>
      </main>
    </template>
  </div>
</template>

<script setup lang="ts">
  import { useSalesStore } from '@/store/modules/sales'
  import axios from 'axios'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'SalesSharePage' })

  const route = useRoute()
  const sales = useSalesStore()

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
  const priceProducts = computed(() => (Array.isArray(data.value?.products) ? data.value.products : []))
  const authedCases = computed(() =>
    (Array.isArray(data.value?.cases) ? data.value.cases : []).filter((c) => c?.authorized)
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
    const share = d.share && typeof d.share === 'object' ? (d.share as Record<string, unknown>) : undefined
    return { ...d, share, info }
  }

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

  watch(
    () => route.params.code,
    async (codeRaw) => {
      const code = String(codeRaw ?? '')
      loading.value = true
      revoked.value = false
      try {
        const base = (import.meta.env.VITE_API_BASE as string) || '/api'
        const resp = await axios.get(`${base.replace(/\/$/, '')}/public/sales/${encodeURIComponent(code)}`)
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

  // 访问量计数：链接确认为有效后再登记（加载完成前 loading 为 true，不会登记）
  watch(
    [loading, invalid],
    () => {
      if (!loading.value && !invalid.value) sales.registerView()
    },
    { immediate: true }
  )
</script>

<style scoped lang="scss">
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

  .tag {
    padding: 1px 8px;
    font-size: 11px;
    color: #2f7d5d;
    cursor: default;
    background: #eef5f0;
    border-radius: 999px;
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

  .cta {
    display: block;
    padding: 11px 0;
    font-size: 14px;
    font-weight: 600;
    color: #fff;
    text-align: center;
    text-decoration: none;
    background: #2f7d5d;
    border-radius: 999px;

    &.secondary {
      color: #2f7d5d;
      background: #fff;
      border: 1px solid #2f7d5d;
    }
  }
</style>
