<template>
  <div class="list-page">
    <!-- 分享控制 -->
    <ElCard shadow="never" class="mb-4">
      <div class="flex flex-wrap items-center gap-4">
        <div>
          <div class="font-500 mb-1">H5 分享页</div>
          <span class="text-xs text-gray-400">手机竖屏版式 · 客户在微信内打开 · 草稿不影响已发布内容（阶段1引入发布版本隔离）</span>
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
        <ElButton type="primary" :disabled="viewingOtherName !== null" @click="preview">预览 / 发送 H5</ElButton>
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
  import { useSalesStore } from '@/store/modules/sales'
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
      ElMessage.warning(`当前展示的是 ${viewingOtherName.value} 的分享，请勿在此开关；如需处置请用运维入口指定链接`)
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

  function preview() {
    const url = `${window.location.origin}${window.location.pathname}#/s/${sales.state.share.code}`
    window.open(url, '_blank')
  }
</script>
