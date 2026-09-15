<template>
  <div class="min-h-100vh bg-[#f6f9f7] pb-12">
    <div v-if="invalid" class="flex-c h-100vh flex-col gap-3 px-6 text-center">
      <img src="@imgs/yimai-logo.png" class="w-14 h-14 object-contain" alt="一麦" />
      <p class="text-gray-500">这份训练方向链接不存在或已停用，请联系你的老师</p>
    </div>

    <template v-else-if="data">
      <!-- 头部 -->
      <header
        class="px-5 pt-8 pb-6 text-white"
        style="background: linear-gradient(135deg, #2f7d5d, #1d5c43)"
      >
        <div class="max-w-100 mx-auto">
          <p class="text-xs opacity-70 mb-1">一麦瑜伽 YIMAI YOGA · 体验课后训练方向</p>
          <h1 class="text-xl font-600">{{ data.studentName }} 的训练方向建议</h1>
          <div class="mt-3 flex flex-wrap gap-2 text-xs">
            <span class="pill">老师 {{ data.teacherName || '—' }}</span>
            <span class="pill">{{ data.classAt || data.confirmedAt }}</span>
            <span v-if="data.studentType" class="pill">{{ data.studentType }}</span>
          </div>
        </div>
      </header>

      <main class="max-w-100 mx-auto px-4">
        <!-- 今天这节课 -->
        <section v-if="script.whatPracticed" class="mt-5 card">
          <h2 class="sec-title">今天这节课，我们一起做了什么</h2>
          <p class="text-sm leading-6">{{ script.whatPracticed }}</p>
        </section>

        <!-- 观察到的 -->
        <section v-if="observed.length" class="mt-5 card">
          <h2 class="sec-title">我在你身上观察到的</h2>
          <ul class="obs-list">
            <li v-for="(o, i) in observed" :key="i">{{ o }}</li>
          </ul>
          <p v-if="script.progress" class="mt-3 text-sm leading-6 progress-box">
            {{ script.progress }}
          </p>
        </section>

        <!-- 训练方向 -->
        <section v-if="phases.length" class="mt-5">
          <h2 class="sec-title px-1">给你的训练方向</h2>
          <div v-for="(p, i) in phases" :key="i" class="card mb-3 relative pl-10">
            <span class="phase-dot">{{ i + 1 }}</span>
            <div class="flex items-baseline justify-between flex-wrap gap-1">
              <h3 class="font-600 text-[15px]">{{ p.name }}</h3>
              <ElTag size="small" effect="plain" type="success">{{ p.duration }}</ElTag>
            </div>
            <p class="mt-1.5 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ p.goal }}</p>
            <ul
              v-if="p.focus?.length"
              class="mt-2 text-[13px] leading-6 text-gray-500 list-disc pl-4"
            >
              <li v-for="(f, fi) in p.focus" :key="fi">{{ f }}</li>
            </ul>
          </div>
        </section>

        <!-- 频率 -->
        <section v-if="objective.frequency" class="mt-5 card">
          <h2 class="sec-title">建议的练习频率</h2>
          <p class="text-sm leading-6">{{ objective.frequency }}</p>
        </section>

        <!-- 回家作业 -->
        <section v-if="homework.length" class="mt-5 card">
          <h2 class="sec-title">回家可以做的 1–2 件事</h2>
          <ul class="obs-list">
            <li v-for="(h, i) in homework" :key="i">{{ h }}</li>
          </ul>
        </section>

        <!-- 下节课 -->
        <section v-if="script.nextClass" class="mt-5 card highlight-card">
          <h2 class="sec-title">下节课我们会继续推进</h2>
          <p class="text-sm leading-6">{{ script.nextClass }}</p>
        </section>

        <!-- 提醒 -->
        <section v-if="script.reminder" class="mt-5 card">
          <h2 class="sec-title">一句回家提醒</h2>
          <p class="text-sm leading-6">{{ script.reminder }}</p>
        </section>

        <!-- 免责声明 -->
        <p v-if="objective.disclaimer" class="mt-6 px-1 text-xs leading-5 text-gray-400">
          {{ objective.disclaimer }}
        </p>

        <!-- 操作 -->
        <div class="mt-6 flex gap-3">
          <ElButton class="flex-1" @click="copyAll">复制全文</ElButton>
          <ElButton v-if="canShare" type="primary" class="flex-1" @click="shareNative"
            >分享</ElButton
          >
        </div>
        <p class="mt-3 text-center text-xs text-gray-400">一麦瑜伽 · 运动训练建议</p>
      </main>
    </template>
  </div>
</template>

<script setup lang="ts">
  import { ElMessage } from 'element-plus'
  import { getPublicPostClass } from '@/api/yimai'

  defineOptions({ name: 'PostClassShare' })

  const route = useRoute()
  const data = ref<Awaited<ReturnType<typeof getPublicPostClass>> | null>(null)
  const invalid = ref(false)

  const objective = computed(() => data.value?.objective ?? ({} as Record<string, never>))
  const phases = computed(() => objective.value.plan ?? [])
  const observed = computed(() => objective.value.observed ?? [])
  const homework = computed(() => objective.value.homework ?? [])
  const script = computed(() => data.value?.script ?? ({} as Record<string, string>))
  const canShare = computed(() => typeof navigator !== 'undefined' && !!navigator.share)

  function buildText(): string {
    const d = data.value
    if (!d) return ''
    const lines: string[] = []
    lines.push(`${d.studentName} 的训练方向建议（一麦瑜伽）`)
    if (script.value.whatPracticed) lines.push('', `今天这节课：${script.value.whatPracticed}`)
    if (observed.value.length) {
      lines.push('', '我在你身上观察到的：')
      observed.value.forEach((o, i) => lines.push(`${i + 1}. ${o}`))
    }
    if (script.value.progress) lines.push('', script.value.progress)
    if (phases.value.length) {
      lines.push('', '给你的训练方向：')
      phases.value.forEach((p) => {
        lines.push(`${p.name}（${p.duration}）：${p.goal}`)
        ;(p.focus ?? []).forEach((f) => lines.push(`  · ${f}`))
      })
    }
    if (objective.value.frequency) lines.push('', `建议的练习频率：${objective.value.frequency}`)
    if (homework.value.length) {
      lines.push('', '回家可以做的：')
      homework.value.forEach((h) => lines.push(`· ${h}`))
    }
    if (script.value.nextClass) lines.push('', `下节课：${script.value.nextClass}`)
    if (script.value.reminder) lines.push('', `提醒：${script.value.reminder}`)
    if (objective.value.disclaimer) lines.push('', objective.value.disclaimer)
    return lines.join('\n')
  }

  async function copyAll() {
    const text = buildText()
    try {
      await navigator.clipboard.writeText(text)
      ElMessage.success('已复制，可直接发给学员')
    } catch {
      ElMessage.warning('复制失败，请长按页面选择文字')
    }
  }

  async function shareNative() {
    try {
      await navigator.share({
        title: `${data.value?.studentName} 的训练方向建议`,
        text: buildText()
      })
    } catch {
      /* 用户取消 */
    }
  }

  onMounted(async () => {
    const code = String(route.params.code ?? '')
    if (!code) {
      invalid.value = true
      return
    }
    try {
      data.value = await getPublicPostClass(code)
    } catch {
      invalid.value = true
    }
  })
</script>

<style scoped lang="scss">
  .card {
    background: #fff;
    border-radius: 12px;
    padding: 14px 16px;
    box-shadow: 0 1px 3px rgb(0 0 0 / 4%);
  }

  .sec-title {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
    color: #1d5c43;
  }

  .obs-list {
    margin: 0;
    padding-left: 18px;
    font-size: 14px;
    line-height: 1.9;
    color: #4b5563;
  }

  .progress-box {
    padding: 10px 12px;
    border-radius: 8px;
    background: #eef8f2;
    color: #1d5c43;
  }

  .highlight-card {
    border: 1px solid #cdebdb;
  }

  .phase-dot {
    position: absolute;
    left: 14px;
    top: 16px;
    width: 22px;
    height: 22px;
    line-height: 22px;
    text-align: center;
    border-radius: 50%;
    background: #2f7d5d;
    color: #fff;
    font-size: 12px;
  }

  .pill {
    padding: 2px 8px;
    border-radius: 10px;
    background: rgb(255 255 255 / 18%);
  }
</style>
