<template>
  <div class="list-page mk-marketing">
    <!--
      横幅：`:title` 已经会渲染 bannerText，slot 里**不能再放一遍**
      —— 之前 slot 里又写了 `{{ bannerText }}`，同一句话在「标题」和「描述」里各渲染一次。
      实测手机端横幅因此高达 186px（其中 72px 是重复文字），桌面端同样重复。
      这里 slot 只留「前往配置」按钮。
    -->
    <ElAlert v-if="isSuper" :title="bannerText" type="info" show-icon :closable="false" class="mb-4">
      <template #default>
        <div class="mk-banner-actions">
          <ElButton link type="primary" @click="$router.push('/yimai/ai-config')">
            前往配置
          </ElButton>
        </div>
      </template>
    </ElAlert>

    <ElCard shadow="never">
      <ElTabs v-model="activeTab">
        <ElTabPane name="moments">
          <template #label>
            <span class="flex items-center gap-1"><ElIcon><ChatDotRound /></ElIcon> 朋友圈</span>
          </template>
          <MomentsTool />
        </ElTabPane>
        <ElTabPane name="xhs">
          <template #label>
            <span class="flex items-center gap-1"><ElIcon><EditPen /></ElIcon> 小红书</span>
          </template>
          <XhsTool />
        </ElTabPane>
      </ElTabs>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import MomentsTool from './modules/moments-tool.vue'
  import XhsTool from './modules/xhs-tool.vue'
  import { useAiConfigStore } from '@/store/modules/ai-config'
  import { useUserStore } from '@/store/modules/user'
  import { ChatDotRound, EditPen } from '@element-plus/icons-vue'

  defineOptions({ name: 'YimaiMarketing' })

  const activeTab = ref<'moments' | 'xhs'>('moments')
  const aiStore = useAiConfigStore()
  const userStore = useUserStore()
  const isSuper = computed(() => (userStore.getUserInfo.roles ?? []).includes('R_SUPER'))

  const bannerText = computed(() =>
    aiStore.isReady()
      ? `AI已接入：${aiStore.config.providerLabel} · ${aiStore.config.model}（生成内容由人工审核后发布）`
      : '当前为本地模板草稿模式 · 超管在「模型配置」接入大模型API后可启用AI生成'
  )
</script>

<style lang="scss">
  /*
    营销工具页（外壳 + 三个子工具）的手持端适配
    =============================================
    这一页原本完全没做手机端降级（MobileCard / isHandheld 命中数均为 0）。实测 390x844 的问题：

    1. 表单控件被压到不可用
       外壳 `.p-4`（左右各 16px）+ 卡片内边距后，表单可用宽度只剩 256px；
       ElFormItem 默认 label 在左（label 68px + 12px 间距），控件因此只剩 **48px 宽**。
       实测「身份角色」「从业年限」「表达方式」等 8 个控件 < 90px —— 完全看不出选了什么。
       → 手机端把 label 移到控件上方（`label-position="top"`），控件拿回整行宽度。

    2. 按钮行被裁切且点不中（比溢出更隐蔽）
       「生成朋友圈 / 历史生成记录 / 朋友圈库」三个按钮合计 359px，而容器只有 244px，
       行是 `flex-wrap: nowrap`。实测第三颗按钮渲染在 x=331→456（视口只有 390），
       `document.elementFromPoint` 返回 null —— **点不中**。
       之所以一直没被发现：祖先卡片是 `overflow-x: hidden`，**把溢出裁掉了**，
       所以 `body.scrollWidth` 依然是 390，页面级「有无横向溢出」的检查永远看不见它。
       → 手机端允许换行（`flex-wrap: wrap`），按钮等宽铺满。

    3. 卡片头换行
       header 是 `flex-cb`（justify-between）且不换行，手机端标题被压成 2 行
       （实测「个人发圈人设」标题 63px 宽/48px 高 = 2 行），右侧标签挤在一起。
       → 手机端改为纵向堆叠。

    样式写在**非 scoped** 块里并统一用 `.mk-marketing` 前缀收敛作用域：
    子组件（modules/*.vue）的模板无法被父组件的 scoped 样式命中，而这些规则三个子工具共用，
    写在各自的 scoped 块里会重复三遍。加前缀后不会外泄到其它页面。
    所有手持端规则一律包在 handheld-only（≤768px）内，桌面端逐值不变。
  */

  // 桌面端与原有 `flex gap-2` / `flex-cb` 完全等价，保证桌面零变化
  .mk-marketing .mk-btn-row {
    display: flex;
    gap: 8px;
  }

  .mk-marketing .mk-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .mk-marketing .mk-banner-actions {
    display: flex;
    justify-content: flex-end;
  }

  // 卡片内的正文摘要：限 4 行，超出省略（卡片是速览，完整内容去「载入编辑」看）
  .mk-marketing .mk-card-text {
    display: -webkit-box;
    margin-top: 8px;
    overflow: hidden;
    font-size: 13px;
    line-height: 1.6;
    color: var(--art-gray-700);
    white-space: pre-wrap;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 4;
  }

  @include handheld-only {
    // 用 `.mk-marketing.list-page`（0,2,0）压过 tailwind 的 `.p-4`（0,1,0），
    // 否则源码顺序会决定胜负、结果不可靠
    .mk-marketing.list-page {
      padding: 8px;
    }

    // 按钮行：允许换行，避免被 overflow-x:hidden 裁掉后点不中
    .mk-marketing .mk-btn-row {
      flex-wrap: wrap;

      .el-button {
        // 铺满剩余宽度：手机上单手点得中，也避免每行只放一个半按钮
        flex: 1 1 auto;
        min-width: 44%;
        margin-left: 0;
      }
    }

    // 卡片头改为纵向堆叠，标题不再被压成两行
    .mk-marketing .mk-card-head {
      flex-direction: column;
      align-items: flex-start;
      gap: 6px;
    }

    /*
      触控目标：单选框与开关
      -----------------------
     实测这两个控件在手机端低于 44px：
       .el-radio-button__inner  72x36（「个人IP / 门店IP」）
       .el-switch               40x32（「同城属性」「多分行」）
     它们不在全局 mobile.scss 的兜底范围内（那份只覆盖 button / pagination /
     input-number / dropdown / tabs），所以在这里补上。

     ⚠️ 这里的 padding / line-height 必须带 !important，并写全
     `.el-radio-button--default .el-radio-button__inner` 两段式：
     el-ui.scss:107 有 `.el-radio-button--default .el-radio-button__inner
     { padding: 10px 15px !important }`（改 default 尺寸按钮组的高度用的）。
     它特异度比我原先的 `.mk-marketing .el-radio-button__inner` 高、又带 !important，
     所以实测 padding 仍是 10px、按钮被顶到 64px。写全两段式 + !important 才能压过它，
     同时把 padding 归零，拿到正好 44px 的按钮。
    */
    .mk-marketing .el-radio-button__inner {
      box-sizing: border-box;
    }

    .mk-marketing .el-radio-button--default .el-radio-button__inner {
      min-height: $touch-target-min !important;
      padding: 0 15px !important;
      line-height: calc(#{$touch-target-min} - 2px) !important;
    }

    .mk-marketing .el-switch {
      min-height: $touch-target-min;
    }

    // 开关滑轨加宽到 46px 更好点中；高度保持 24px 不变形
    .mk-marketing .el-switch__core {
      min-width: 46px;
      height: 24px;
    }
  }
</style>
