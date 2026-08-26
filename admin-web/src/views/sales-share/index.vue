<template>
  <div class="min-h-100vh bg-[#faf7f2] pb-10">
    <div v-if="invalid" class="flex-c h-100vh flex-col gap-3">
      <img src="@imgs/yimai-logo.png" class="w-14 h-14 object-contain" alt="一麦" />
      <p class="text-gray-500">链接已失效或已停用，请联系门店获取最新资料</p>
    </div>

    <template v-else>
      <!-- 品牌头 -->
      <header class="px-5 pt-8 pb-6 text-center text-white" style="background: linear-gradient(135deg, #2f7d5d, #1d5c43)">
        <h1 class="text-xl font-600 tracking-wide">{{ info.name }}</h1>
        <p class="mt-1 text-sm opacity-80">{{ info.industry }}</p>
        <p class="mt-4 text-lg font-500">「 {{ info.slogan }} 」</p>
        <p class="mt-2 text-xs opacity-70 max-w-70 mx-auto">{{ info.intro }}</p>
      </header>

      <main class="max-w-100 mx-auto px-4">
        <!-- 产品与价目 -->
        <section class="mt-5">
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

        <!-- 推荐教练 -->
        <section class="mt-5">
          <h2 class="sec-title">推荐教练</h2>
          <div v-for="c in coaches" :key="c.id" class="card mb-3 flex gap-3 items-start">
            <div class="coach-avatar flex-none">{{ c.name.slice(0, 1) }}</div>
            <div class="min-w-0">
              <div class="flex items-baseline gap-2">
                <span class="font-600">{{ c.name }}</span>
                <span class="text-xs text-gray-400">{{ c.title }}</span>
              </div>
              <div class="mt-1 flex flex-wrap gap-1">
                <span v-for="t in c.tags" :key="t" class="tag">{{ t }}</span>
              </div>
              <p class="text-xs text-gray-500 mt-1 leading-5">{{ c.intro }}</p>
            </div>
          </div>
        </section>

        <!-- 学员案例（仅已授权） -->
        <section v-if="authedCases.length" class="mt-5">
          <h2 class="sec-title">学员案例</h2>
          <p class="text-xs text-gray-400 mb-2">以下案例均取得会员授权 · 展示不含面部信息</p>
          <div v-for="c in authedCases" :key="c.id" class="card mb-3">
            <div class="text-xs text-gray-400 mb-1">目标</div>
            <h3 class="font-600">{{ c.goal }}</h3>
            <p class="text-xs text-gray-500 mt-1 leading-5">{{ c.desc }}</p>
            <div v-if="coachName(c)" class="mt-2 text-xs"><ElTag size="small" effect="plain">指导教练：{{ coachName(c) }}</ElTag></div>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <ElTag v-for="(s, si) in c.stages" :key="si" size="small" :effect="si === 0 ? 'plain' : 'dark'" type="success">
                {{ si === 0 ? '初始' : s.duration || `阶段${si}` }}
              </ElTag>
            </div>
          </div>
        </section>

        <!-- 到店信息 -->
        <section class="mt-5">
          <h2 class="sec-title">到店信息</h2>
          <div class="card space-y-2 text-sm">
            <p>📍 {{ info.address }}</p>
            <p>📞 {{ info.phone }}</p>
          </div>
          <div class="mt-4 grid grid-cols-2 gap-3">
            <a class="cta" :href="`tel:${info.phone.replace(/-/g, '')}`">电话咨询</a>
            <button class="cta secondary" @click="copyAddress">复制地址</button>
          </div>
          <p class="mt-4 text-center text-xs text-gray-400">© 一麦瑜伽 · 双店运营</p>
        </section>
      </main>
    </template>
  </div>
</template>

<script setup lang="ts">
  import { useSalesStore } from '@/store/modules/sales'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'SalesSharePage' })

  const route = useRoute()
  const sales = useSalesStore()

  const invalid = computed(() => {
    if (!sales.state.share.enabled) return true
    return route.params.code !== sales.state.share.code
  })

  const info = computed(() => sales.state.info)
  const coaches = computed(() => sales.state.coaches)
  const priceProducts = computed(() => sales.state.products)
  const authedCases = computed(() => sales.state.cases.filter((c) => c.authorized))

  function coachName(c: { coachId: number | '' }): string {
    if (c.coachId === '') return ''
    return coaches.value.find((x) => x.id === c.coachId)?.name ?? ''
  }

  async function copyAddress() {
    try {
      await navigator.clipboard.writeText(info.value.address)
      ElMessage.success('地址已复制')
    } catch {
      ElMessage.warning(info.value.address)
    }
  }

  onMounted(() => {
    if (!invalid.value) sales.registerView()
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
