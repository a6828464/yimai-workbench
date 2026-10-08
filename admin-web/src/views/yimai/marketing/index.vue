<template>
  <div class="list-page mk-marketing">
    <!--
      横幅：`:title` 已经会渲染 bannerText，slot 里**不能再放一遍**
      —— 之前 slot 里又写了 `{{ bannerText }}`，同一句话在「标题」和「描述」里各渲染一次。
      实测手机端横幅因此高达 186px（其中 72px 是重复文字），桌面端同样重复。
      这里 slot 只留「前往配置」按钮。
    -->
    <ElAlert
      v-if="isSuper"
      :title="bannerText"
      type="info"
      show-icon
      :closable="false"
      class="mb-4"
    >
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
            <span class="flex items-center gap-1"
              ><ElIcon><ChatDotRound /></ElIcon> 朋友圈</span
            >
          </template>
          <MomentsTool />
        </ElTabPane>
        <ElTabPane name="xhs">
          <template #label>
            <span class="flex items-center gap-1"
              ><ElIcon><EditPen /></ElIcon> 小红书</span
            >
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
  import { useMarketingFavorites } from './modules/marketing-favorites'
  import { useAiConfigStore } from '@/store/modules/ai-config'
  import { useUserStore } from '@/store/modules/user'
  import { ChatDotRound, EditPen } from '@element-plus/icons-vue'

  defineOptions({ name: 'YimaiMarketing' })

  const activeTab = ref<'moments' | 'xhs'>('moments')
  const aiStore = useAiConfigStore()
  const userStore = useUserStore()
  const isSuper = computed(() => (userStore.getUserInfo.roles ?? []).includes('R_SUPER'))

  /**
   * 收藏同步的主触发（规格 §5.5）：登录态就绪后**静默**拉一次云端收藏并跑旧收藏导入。
   *
   * 为什么不能只靠「打开收藏抽屉」：升级后本机若有尚未导入服务端的旧收藏，
   * 用户在打开抽屉之前登出，PII 清理会把它们永久抹掉。主触发把窗口压到秒级。
   * 静默 = 不弹 toast、失败也不打扰；标记未写入则下次登录自动重试（去重键保证幂等）。
   */
  const { syncOnLogin } = useMarketingFavorites()
  onMounted(() => {
    void syncOnLogin()
  })

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

  /*
    ↓↓↓ 本轮（I2）新增：选项口径说明 / 多结果卡片 / 质检面板 / 提示词查看器 / 图片素材 ↓↓↓

    这些规则三个子工具共用，统一写在非 scoped 块里并用 .mk-marketing 收口作用域，
    避免在三份 scoped 样式里各写一遍。桌面端为默认值，手持端规则一律包在 handheld-only 内。
  */

  // 口径说明块：让「这一档约束了什么」在界面上可辨
  .mk-marketing .mk-hint-block {
    padding: 8px 10px;
    margin-bottom: 12px;
    font-size: 12px;
    line-height: 1.7;
    color: var(--art-gray-600);
    background: var(--art-gray-100);
    border-radius: 8px;

    b {
      color: var(--art-gray-800);
      font-weight: 500;
    }
  }

  // 表单区小标题（图片素材 / 质检分组）
  .mk-marketing .mk-sub-title {
    margin-bottom: 4px;
    font-size: 13px;
    font-weight: 500;
    color: var(--art-gray-800);
  }

  // 生成铁律折叠面板
  .mk-marketing .mk-rule-collapse {
    margin-bottom: 12px;
  }

  .mk-marketing .mk-rule-item {
    &__label {
      font-size: 12px;
      font-weight: 500;
      color: var(--art-gray-700);
    }

    &__body {
      margin: 2px 0 10px;
      font-size: 12px;
      line-height: 1.7;
      color: var(--art-gray-600);
      white-space: pre-wrap;
    }
  }

  // 图片素材：预览卡片 + 每张一句说明
  .mk-marketing .mk-image-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .mk-marketing .mk-image-add {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    color: var(--art-gray-500);
  }

  .mk-marketing .mk-image-notes {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 12px;
  }

  .mk-marketing .mk-image-note {
    display: flex;
    align-items: center;
    gap: 8px;

    &__idx {
      flex: none;
      width: 42px;
      font-size: 12px;
      color: var(--art-gray-500);
    }
  }

  // 多结果卡片：桌面端两列并排，一条结果一张卡
  .mk-marketing .mk-result-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 12px;
  }

  .mk-marketing .mk-result-card {
    padding: 12px;
    border: 1px solid var(--art-card-border);
    border-radius: calc(var(--custom-radius) / 2 + 2px);

    &__head {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
    }

    &__title {
      font-size: 13px;
      font-weight: 600;
      color: var(--art-gray-900);
    }

    &__warn {
      font-size: 12px;
      color: var(--el-color-danger);
    }

    &__ok {
      font-size: 12px;
      color: var(--el-color-success);
    }

    // 三态里的第三态：本卡没有质检项时如实说明（不得用「通过」冒充）
    &__none {
      font-size: 12px;
      color: var(--art-gray-500);
    }
  }

  .mk-marketing .mk-result-empty {
    padding: 24px 0;
    font-size: 13px;
    color: var(--art-gray-500);
    text-align: center;
  }

  // 朋友圈预览（每条独立，避免多条结果共用同一块预览）
  .mk-marketing .mk-moment-preview {
    padding: 16px;
    background: #fff;
    border: 1px solid var(--art-card-border);
    border-radius: 8px;

    &__body {
      margin-top: 12px;
      font-size: 15px;
      line-height: 1.8;
      color: var(--art-gray-800);
      word-break: break-all;
      white-space: pre-wrap;
    }
  }

  .dark .mk-marketing .mk-moment-preview {
    background: #0d0d0d;
  }

  // 首条回复：跟在自己那条结果下面（不串位）
  .mk-marketing .mk-reply-card {
    padding: 12px;
    margin-top: 12px;
    border: 1px dashed var(--art-gray-300);
    border-radius: 8px;
  }

  // 质检明细：通过为灰、未通过为红（数据全部来自 api 层质检函数）
  .mk-marketing .mk-check-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px 10px;
    margin-top: 12px;
    background: var(--art-gray-100);
    border-radius: 8px;
  }

  .mk-marketing .mk-check-item {
    display: flex;
    gap: 6px;
    font-size: 12px;
    line-height: 1.6;
    color: var(--art-gray-600);

    &__label {
      flex: none;
      min-width: 92px;
      font-weight: 500;
      color: var(--art-gray-700);
    }

    &__detail {
      min-width: 0;
      word-break: break-all;
    }

    &.is-fail {
      color: var(--el-color-danger);

      .mk-check-item__label {
        color: var(--el-color-danger);
      }
    }
  }

  .mk-marketing .mk-issue-list {
    padding-left: 18px;
    margin-top: 4px;
    font-size: 12px;
    line-height: 1.8;
    color: var(--el-color-danger);
    list-style: disc;
  }

  // 防折叠：未通过项之外的自报提醒
  .mk-marketing .mk-warn-list {
    padding: 8px 10px;
    margin-top: 8px;
    font-size: 12px;
    line-height: 1.7;
    color: var(--el-color-warning);
    background: var(--el-color-warning-light-9);
    border-radius: 8px;
  }

  // 收藏卡上的首评 / 封面文案（老收藏缺这两个字段时不渲染，不留空壳）
  .mk-marketing .mk-fav-reply {
    padding: 4px 8px;
    margin-top: 6px;
    font-size: 12px;
    line-height: 1.6;
    color: var(--art-gray-600);
    background: var(--art-gray-100);
    border-radius: 6px;
  }

  .mk-marketing .mk-fav-cover {
    margin-top: 4px;
    font-size: 12px;
    line-height: 1.6;
    color: var(--art-gray-600);
  }

  // 小红书标签胶囊
  .mk-marketing .mk-tag-pill {
    padding: 2px 8px;
    font-size: 12px;
    color: #ff2442;
    background: rgb(255 36 66 / 10%);
    border-radius: 999px;
  }

  // 小红书笔记预览卡
  .mk-marketing .mk-note-preview {
    overflow: hidden;
    background: #fff;
    border: 1px solid var(--art-card-border);
    border-radius: 8px;

    &__title {
      padding: 16px 16px 0;
      font-size: 16px;
      font-weight: 700;
      line-height: 1.4;
      color: var(--art-gray-900);
    }

    &__body {
      padding: 12px 16px;
      font-size: 14px;
      line-height: 1.85;
      color: var(--art-gray-700);
      word-break: break-all;
      white-space: pre-wrap;
    }
  }

  .dark .mk-marketing .mk-note-preview {
    background: #0d0d0d;
  }

  // 封面文案卡：独立可见、可单独复制
  .mk-marketing .mk-cover-card {
    padding: 10px 12px;
    margin-bottom: 10px;
    background: var(--el-color-primary-light-9);
    border-radius: 8px;
  }

  // IP 定位行：单选组 + 建议按钮
  .mk-marketing .mk-ip-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
  }

  // 「已保存项超过上限」的显式提示（不静默截断）
  .mk-marketing .mk-overflow-tip {
    margin-top: 4px;
    font-size: 12px;
    line-height: 1.7;
    color: var(--el-color-warning);

    b {
      font-weight: 600;
      color: var(--el-color-danger);
    }
  }

  // 提示词查看器：原文等宽展示，可横向滚动（内容长，不折行更好读）
  .mk-marketing .mk-prompt-block {
    margin-bottom: 14px;

    &__role {
      font-size: 12px;
      font-weight: 500;
      color: var(--art-gray-700);
    }

    &__body {
      max-height: 320px;
      padding: 10px;
      margin: 4px 0;
      overflow: auto;
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size: 12px;
      line-height: 1.7;
      color: var(--art-gray-700);
      white-space: pre-wrap;
      word-break: break-all;
      background: var(--art-gray-100);
      border-radius: 8px;
    }
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

    /*
      本轮（I2）新增项的手持端补充
      ---------------------------
      1. 多结果卡片：手机端单列铺满（桌面是 auto-fill 多列），否则两条结果会挤成半宽。
      2. 微信图片九宫格：每格必须 ≥44px，否则「加图」格和每格的删除按钮点不中。
      3. 图片说明行：编号 + 输入框 + 移除按钮在手机端会挤，这里让说明行纵向堆叠。
      4. 质检项：桌面端 label 固定 92px，手机端改为纵向堆叠，避免 detail 被压成竖排单字。
      5. ElMessageBox / 按钮行内的链接按钮：全局 mobile.scss 已兜底 44px，这里只补上传格。
    */
    .mk-marketing .mk-result-list {
      grid-template-columns: 1fr;
    }

    .mk-marketing .el-upload--picture-card,
    .mk-marketing .el-upload-list--picture-card .el-upload-list__item {
      width: $touch-target-min !important;
      height: $touch-target-min !important;
    }

    .mk-marketing .mk-image-note {
      flex-direction: column;
      align-items: flex-start;
      gap: 4px;
      padding-bottom: 8px;
      border-bottom: 1px dashed var(--art-card-border);

      &__idx {
        width: auto;
      }
    }

    .mk-marketing .mk-check-item {
      flex-direction: column;
      gap: 0;

      &__label {
        min-width: 0;
      }
    }

    // 提示词原文：手机端限制高度，避免一条提示词把抽屉顶到无法滚动
    .mk-marketing .mk-prompt-block__body {
      max-height: 220px;
    }
  }
</style>
