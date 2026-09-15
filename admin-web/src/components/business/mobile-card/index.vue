<!--
  MobileCard - 手持设备上的单条数据卡片

  宽表格在手机上不可用的根因是「左右固定列吃掉了全部可见宽度」，所以窄屏一律换卡片呈现。
  切换阈值与理由见 src/config/breakpoints.ts 与 src/assets/styles/core/mobile.scss。

  用法：
    <div class="m-card-list">
      <MobileCard
        v-for="row in list"
        :key="row.id"
        :title="row.name"
        :subtitle="`${row.phone} · ${row.source}`"
        :tags="[{ text: row.venue, effect: 'plain' }]"
        :metrics="[{ label: '剩余课时', value: row.remainTimes ?? '—', unit: '节', danger: row.remainTimes < 10 }]"
        :actions="[
          { text: '续费评估', type: 'primary', onClick: () => openEval(row) },
          { text: '生日', onClick: () => openBirthday(row) }
        ]"
      >
        <template #note>续课计划：待教练月度预报</template>
      </MobileCard>
    </div>
-->
<template>
  <div class="mobile-card">
    <!-- 标题行 -->
    <div class="mobile-card__head">
      <div class="mobile-card__title-wrap">
        <div class="mobile-card__title">{{ title }}</div>
        <div v-if="subtitle" class="mobile-card__subtitle">{{ subtitle }}</div>
      </div>
      <slot name="head-extra" />
    </div>

    <!-- 标签行 -->
    <div v-if="visibleTags.length" class="mobile-card__tags">
      <ElTag
        v-for="(tag, i) in visibleTags"
        :key="`${tag.text}-${i}`"
        size="small"
        :type="tag.type"
        :effect="tag.effect ?? 'light'"
      >
        {{ tag.text }}
      </ElTag>
    </div>

    <!-- 指标行 -->
    <div v-if="visibleMetrics.length" class="mobile-card__metrics">
      <div v-for="metric in visibleMetrics" :key="metric.label" class="mobile-card__metric">
        <div class="mobile-card__metric-label">{{ metric.label }}</div>
        <div class="mobile-card__metric-value" :class="{ 'is-danger': metric.danger }">
          {{ metric.value }}
          <span v-if="metric.unit" class="mobile-card__metric-unit">{{ metric.unit }}</span>
        </div>
      </div>
    </div>

    <!-- 补充说明（续课计划 / 下降原因 / 复活跟进 等） -->
    <div v-if="note || $slots.note" class="mobile-card__note" :class="{ 'is-danger': noteDanger }">
      <span v-if="noteLabel" class="mobile-card__note-label">{{ noteLabel }}：</span>
      <slot name="note">{{ note }}</slot>
    </div>

    <!-- 页面自定义的其它内容 -->
    <slot />

    <!-- 操作区 -->
    <div v-if="inlineActions.length || moreActions.length" class="mobile-card__actions">
      <ElButton
        v-for="action in inlineActions"
        :key="action.text"
        class="mobile-card__btn"
        :type="action.type"
        plain
        @click="action.onClick"
      >
        {{ action.text }}
      </ElButton>

      <ElDropdown v-if="moreActions.length" trigger="click" placement="top-end" @command="runMore">
        <ElButton class="mobile-card__btn" plain>
          更多
          <ArtSvgIcon icon="ri:arrow-down-s-line" class="ml-0.5" />
        </ElButton>
        <template #dropdown>
          <ElDropdownMenu>
            <ElDropdownItem v-for="action in moreActions" :key="action.text" :command="action">
              {{ action.text }}
            </ElDropdownItem>
          </ElDropdownMenu>
        </template>
      </ElDropdown>
    </div>
  </div>
</template>

<script setup lang="ts">
  import type { MobileCardAction, MobileCardMetric, MobileCardTag } from './types'

  defineOptions({ name: 'MobileCard' })

  const props = withDefaults(
    defineProps<{
      /** 主标题，通常是姓名 */
      title: string
      /** 副标题，通常是联系方式 / 来源 */
      subtitle?: string
      /** 顶部标签 */
      tags?: MobileCardTag[]
      /** 关键指标，建议 2–4 个 */
      metrics?: MobileCardMetric[]
      /** 补充说明文字 */
      note?: string
      /** 补充说明的标签，如「续课计划」 */
      noteLabel?: string
      /** 补充说明是否标红 */
      noteDanger?: boolean
      /** 操作按钮 */
      actions?: MobileCardAction[]
      /**
       * 直接平铺展示的操作个数，其余收进「更多」
       * 卡片上按钮太多会变成一面墙，默认只平铺 2 个
       */
      maxInlineActions?: number
    }>(),
    {
      tags: () => [],
      metrics: () => [],
      actions: () => [],
      maxInlineActions: 2
    }
  )

  const visibleTags = computed(() => props.tags.filter((t) => t.text))
  const visibleMetrics = computed(() => props.metrics.filter((m) => m.label))
  const visibleActions = computed(() => props.actions.filter((a) => a.show !== false))
  const inlineActions = computed(() => visibleActions.value.slice(0, props.maxInlineActions))
  const moreActions = computed(() => visibleActions.value.slice(props.maxInlineActions))

  const runMore = (action: MobileCardAction): void => {
    action.onClick()
  }
</script>

<style scoped lang="scss">
  .mobile-card {
    padding: 14px;
    background: var(--default-box-color);
    border: 1px solid var(--art-card-border);
    border-radius: calc(var(--custom-radius) / 2 + 2px);

    &__head {
      display: flex;
      gap: 8px;
      align-items: flex-start;
      justify-content: space-between;
    }

    // min-width: 0 让长名字可以省略号截断，而不是把卡片撑宽
    &__title-wrap {
      min-width: 0;
    }

    &__title {
      overflow: hidden;
      font-size: 15px;
      font-weight: 600;
      color: var(--art-gray-900);
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    &__subtitle {
      margin-top: 2px;
      overflow: hidden;
      font-size: 12px;
      color: var(--art-gray-600);
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    &__tags {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 10px;
    }

    &__metrics {
      display: flex;
      flex-wrap: wrap;
      gap: 10px 20px;
      margin-top: 12px;

      // 指标之间用细分隔线区分，比留白更清楚
      > .mobile-card__metric + .mobile-card__metric {
        padding-left: 20px;
        border-left: 1px solid var(--art-card-border);
      }
    }

    &__metric-label {
      font-size: 12px;
      color: var(--art-gray-500);
    }

    &__metric-value {
      margin-top: 2px;
      font-size: 16px;
      font-weight: 600;
      color: var(--art-gray-900);

      &.is-danger {
        color: var(--el-color-danger);
      }
    }

    &__metric-unit {
      margin-left: 1px;
      font-size: 12px;
      font-weight: 400;
      color: var(--art-gray-500);
    }

    &__note {
      padding: 8px 10px;
      margin-top: 10px;
      font-size: 13px;
      line-height: 1.6;
      color: var(--art-gray-700);
      background: var(--art-gray-100);
      border-radius: 8px;

      &.is-danger {
        color: var(--el-color-danger);
        background: var(--el-color-danger-light-9);
      }
    }

    &__note-label {
      margin-right: 2px;
      color: var(--art-gray-500);
    }

    &__actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 12px;
    }

    // 触控区不小于 44px：老师是站着单手操作的
    &__btn {
      flex: 1 1 auto;
      min-width: 84px;
      min-height: 44px;
      margin-left: 0 !important;
    }
  }
</style>
