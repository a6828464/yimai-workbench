<template>
  <div class="min-h-100vh bg-[#f6f9f7] pb-10">
    <div v-if="invalid" class="flex-c h-100vh flex-col gap-3 px-6 text-center">
      <img src="@imgs/yimai-logo.png" class="w-14 h-14 object-contain" alt="一麦" />
      <p class="text-gray-500">计划链接不存在或已停用，请联系你的教练</p>
    </div>

    <template v-else-if="plan && plan.content">
      <!-- 头部 -->
      <header class="px-5 pt-8 pb-6 text-white" style="background: linear-gradient(135deg, #2f7d5d, #1d5c43)">
        <div class="max-w-100 mx-auto">
          <p class="text-xs opacity-70 mb-1">一麦瑜伽 · 专属训练计划</p>
          <h1 class="text-xl font-600">{{ plan.memberName }} 的{{ plan.stageWeeks }}周练习方案</h1>
          <p class="mt-2 text-sm opacity-85">核心目标：{{ plan.coreGoal }}</p>
          <div class="mt-3 flex flex-wrap gap-2 text-xs">
            <span class="pill">教练 {{ plan.createdBy }}</span>
            <span class="pill">{{ plan.freq }}</span>
            <span class="pill">共 {{ plan.content.phases.length }} 个阶段</span>
          </div>
        </div>
      </header>

      <main class="max-w-100 mx-auto px-4">
        <!-- 摘要 -->
        <section class="mt-5 card">
          <h2 class="sec-title">计划概要</h2>
          <p class="text-sm leading-6">{{ plan.content.summary }}</p>
          <div v-if="plan.stageGoal" class="mt-3 text-xs bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-300 rounded-lg p-2.5">
            本阶段目标：{{ plan.stageGoal }}
          </div>
        </section>

        <!-- 阶段安排 -->
        <section class="mt-5">
          <h2 class="sec-title">阶段安排</h2>
          <div v-for="(ph, i) in plan.content.phases" :key="i" class="card mb-3 relative pl-10">
            <span class="phase-dot">{{ i + 1 }}</span>
            <div class="flex items-baseline justify-between flex-wrap gap-1">
              <h3 class="font-600">{{ ph.name }}</h3>
              <ElTag size="small" effect="plain" type="success">{{ ph.duration }}</ElTag>
            </div>
            <ul class="mt-1.5 text-sm leading-6 text-gray-600 dark:text-gray-300 list-disc pl-4">
              <li v-for="it in ph.items" :key="it">{{ it }}</li>
            </ul>
          </div>
        </section>

        <!-- 对比照 -->
        <section v-if="plan.images.length" class="mt-5">
          <h2 class="sec-title">练习记录 · 对比照</h2>
          <p class="text-xs text-gray-400 mb-2">已取得会员授权展示 · 不含面部信息</p>
          <div class="grid grid-cols-2 gap-3">
            <div v-for="img in plan.images" :key="img.id" class="photo-card">
              <ElImage :src="img.url" fit="cover" :preview-src-list="[img.url]" preview-teleported class="w-full h-40 rounded-t-xl" />
              <div class="text-xs text-center py-1.5 bg-white dark:bg-gray-800 rounded-b-xl">{{ img.label }}</div>
            </div>
          </div>
        </section>

        <!-- 注意事项 -->
        <section class="mt-5">
          <h2 class="sec-title">注意事项</h2>
          <div class="card space-y-1.5">
            <p v-for="ct in plan.content.cautions" :key="ct" class="text-sm leading-6 text-orange-700 dark:text-orange-400">· {{ ct }}</p>
          </div>
        </section>

        <footer class="mt-8 text-center text-xs text-gray-400">
          © 一麦瑜伽 · 如有疑问请联系你的教练
          <br />确认时间：{{ plan.confirmedAt || plan.createdAt }}
        </footer>
      </main>
    </template>

    <div v-else class="flex-c h-100vh"><span class="loading-text">加载中...</span></div>
  </div>
</template>

<script setup lang="ts">
  import { useTrainingStore } from '@/store/modules/training'
  import type { TrainingPlan } from '@/store/modules/training'
  import axios from 'axios'
  import { ElImage, ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'TrainingSharePage' })

  const route = useRoute()
  const trainingStore = useTrainingStore()

  /**
   * 数据来源：
   * 1. 后端公开接口 /api/public/training/{code}（跨设备，微信内打开）
   * 2. 本地 store（演示模式/同浏览器）
   */
  const plan = ref<TrainingPlan | null>(null)
  const loading = ref(true)

  watch(
    () => route.params.code,
    async (codeRaw) => {
      const code = String(codeRaw ?? '')
      loading.value = true
      try {
        const base = (import.meta.env.VITE_API_BASE as string) || '/api'
        const resp = await axios.get(`${base.replace(/\/$/, '')}/public/training/${encodeURIComponent(code)}`)
        plan.value = resp.data?.data as TrainingPlan
      } catch {
        // 后端不可用或演示模式：回退本地
        plan.value = trainingStore.state.plans.find((p) => p.share.code === code) ?? null
      } finally {
        loading.value = false
      }
    },
    { immediate: true }
  )

  const invalid = computed(() => {
    const p = plan.value
    if (!p) return true
    if (p.status !== '已确认') return true
    if (!p.share.enabled) return true
    return false
  })

  onMounted(() => {
    if (!invalid.value) {
      trainingStore.registerPlanView(String(route.params.code))
    } else if (plan.value) {
      ElMessage.closeAll()
    }
  })
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

  .pill {
    padding: 2px 10px;
    font-size: 11px;
    background: rgb(255 255 255 / 14%);
    border-radius: 999px;
  }

  .phase-dot {
    position: absolute;
    top: 16px;
    left: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    font-size: 12px;
    font-weight: 600;
    color: #fff;
    background: #2f7d5d;
    border-radius: 50%;
  }

  .photo-card {
    overflow: hidden;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgb(31 92 66 / 8%);
  }

  .loading-text {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }
</style>
