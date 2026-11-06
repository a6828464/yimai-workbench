<template>
  <div class="list-page">
    <ElCard shadow="never" class="mb-3">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">小程序设置</span>
          <div class="flex items-center gap-3">
            <span class="text-xs text-gray-400">最近保存：{{ updatedAt || '从未保存' }}</span>
            <ElButton link type="primary" :loading="loading" @click="load">重新读取</ElButton>
          </div>
        </div>
      </template>

      <ElAlert type="info" :closable="false" show-icon class="mb-3" title="这里改的是顾客端">
        <div class="text-xs leading-5">
          小程序「到店指引」页显示的门店副标题、地址、电话、坐标与引导图，全部来自这份配置。
          改完保存即可生效，不需要发版。
          <b>门店名称与门店数量由系统固定</b>（两家店），此处不能新增或删除门店 ——
          后端同样会拒绝，避免两端对不上。
        </div>
      </ElAlert>

      <!-- 从未保存过：后端回落内置默认值，不能说成「已配置」 -->
      <ElAlert
        v-if="!updatedAt && !loading"
        type="warning"
        :closable="false"
        show-icon
        class="mb-3"
        title="当前显示的是系统默认值（从未保存过），保存后才会写入数据库并在小程序生效"
      />

      <ElAlert
        v-if="demoNotice"
        type="warning"
        :closable="false"
        show-icon
        class="mb-3"
        :title="demoNotice"
      />

      <!-- 服务端 422 的字段级错误 / 403 无权限：原样展示，不吞成「保存失败」 -->
      <ElAlert
        v-if="serverErrors.length"
        type="error"
        :closable="false"
        show-icon
        class="mb-3"
        :title="errorTitle"
      >
        <ul class="c-red text-xs leading-6">
          <li v-for="(msg, i) in serverErrors" :key="i">· {{ msg }}</li>
        </ul>
      </ElAlert>

      <ElTabs v-if="venues.length" v-model="activeKey">
        <ElTabPane v-for="v in venues" :key="v.key" :label="v.key" :name="v.key">
          <ElForm label-width="104px" class="pt-2">
            <ElFormItem label="门店">
              <div class="flex items-center gap-2">
                <ElTag type="info" size="small">{{ v.key }}</ElTag>
                <span class="text-xs text-gray-400">只读：门店名与门店数量固定，不可新增/删除</span>
              </div>
            </ElFormItem>

            <ElFormItem label="副标题">
              <ElInput
                v-model="v.sub"
                maxlength="40"
                show-word-limit
                placeholder="如：绿地缤纷城 · 4 楼"
              />
              <div class="field-tip">显示在顾客端「到店指引」页门店名下方，一句话说清位置。</div>
            </ElFormItem>

            <ElFormItem label="门店地址">
              <ElInput
                v-model="v.address"
                type="textarea"
                :rows="2"
                maxlength="120"
                show-word-limit
                placeholder="如：宁波市江北区绿地缤纷城1号楼4楼"
              />
              <div class="field-tip">
                顾客点「复制地址」复制的就是这一串，请写到门牌号；
                <b>清空并保存即表示「地址待补」</b>，小程序会隐藏复制入口。
              </div>
            </ElFormItem>

            <ElFormItem label="联系电话">
              <ElInput v-model="v.phone" maxlength="30" placeholder="如：0574-87214863" />
              <div class="field-tip">
                顾客点「拨打电话」时拨打这个号码；留空并保存则小程序不显示拨号按钮。
              </div>
            </ElFormItem>

            <ElFormItem label="纬度 / 经度">
              <div class="flex items-center gap-2">
                <ElInputNumber
                  v-model="v.latitude"
                  :controls="false"
                  :precision="6"
                  :min="LAT_RANGE[0]"
                  :max="LAT_RANGE[1]"
                  placeholder="纬度"
                  class="w-[170px]"
                />
                <ElInputNumber
                  v-model="v.longitude"
                  :controls="false"
                  :precision="6"
                  :min="LNG_RANGE[0]"
                  :max="LNG_RANGE[1]"
                  placeholder="经度"
                  class="w-[170px]"
                />
              </div>
              <div class="field-tip">
                两个都填才有「导航」按钮。<b class="c-orange">留空则小程序降级为「复制地址」</b>，
                不会把顾客导到错误位置；经纬度可在地图 App 里长按门店位置复制。 合理范围：纬度
                {{ LAT_RANGE[0] }}–{{ LAT_RANGE[1] }}、经度 {{ LNG_RANGE[0] }}–{{
                  LNG_RANGE[1]
                }}（与后端同一套校验，超范围会被拒）。
              </div>
            </ElFormItem>

            <ElFormItem label="视频号">
              <ElInput
                v-model="v.channelsUser"
                maxlength="120"
                placeholder="可留空，如：一麦瑜伽"
              />
              <div class="field-tip"
                >门店自己的视频号 ID，顾客端用于「看门店视频」入口；没有就留空。</div
              >
            </ElFormItem>

            <ElFormItem label="直播 ID">
              <ElInput
                v-model="v.channelsLive"
                maxlength="120"
                placeholder="可留空，如：live_xxxx"
              />
              <div class="field-tip">视频号直播间 ID，留空则顾客端不显示直播入口。</div>
            </ElFormItem>

            <ElFormItem label="引导图">
              <div class="w-full">
                <div v-for="(img, idx) in v.images" :key="idx" class="img-block">
                  <div class="img-row">
                    <ElInput
                      v-model="img.src"
                      maxlength="200"
                      placeholder="/static/hero.jpg 或 https://..."
                      @input="img.broken = false"
                    />
                    <ElInput
                      v-model="img.caption"
                      maxlength="120"
                      placeholder="说明，如：出电梯右手边即前台"
                    />
                    <ElButton link type="danger" @click="removeImage(v, idx)">删除</ElButton>
                  </div>
                  <div class="img-check">
                    <div class="thumb">
                      <img
                        v-if="img.src && !img.broken"
                        :src="img.src"
                        alt="引导图预览"
                        @error="img.broken = true"
                        @load="img.broken = false"
                      />
                      <span v-else class="thumb-ph">{{ img.src ? '地址不可用' : '未填地址' }}</span>
                    </div>
                    <span v-if="srcError(img.src)" class="c-red text-xs"
                      >✕ {{ srcError(img.src) }}</span
                    >
                    <span v-else-if="img.broken" class="c-red text-xs">
                      ✕ 这张图加载不出来（地址不可访问）—— 仍可保存，但顾客端也会是空白
                    </span>
                    <span v-else-if="img.src" class="text-xs text-gray-400"
                      >✓ 可预览，保存后顾客端按此顺序展示</span
                    >
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <ElButton :disabled="v.images.length >= MAX_IMAGES" @click="addImage(v)">
                    新增引导图
                  </ElButton>
                  <span class="text-xs text-gray-400">
                    最多 {{ MAX_IMAGES }} 张，当前 {{ v.images.length }} 张
                  </span>
                </div>
                <div class="field-tip">
                  顾客按顺序看到这些图与说明；地址只接受<b>站内路径（/static/xxx.jpg）</b>或
                  <b>https:// 外链</b>，http 与 javascript: 会被后端拒绝，这里提前拦住。
                </div>
              </div>
            </ElFormItem>
          </ElForm>
        </ElTabPane>
      </ElTabs>

      <ElEmpty
        v-else-if="!loading"
        description="没有读到门店配置（后端 /miniapp/config 未就绪或返回空）"
      />

      <div class="mt-3 flex items-center gap-2">
        <ElButton type="primary" :loading="saving" :disabled="!venues.length" @click="save">
          保存
        </ElButton>
        <ElButton :disabled="loading" @click="load">放弃修改并重读</ElButton>
        <span class="text-xs text-gray-400">保存后立即对微信小程序生效，无需发版</span>
      </div>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { apiGet, apiPut, showApiError, toHttpError, USE_BACKEND } from '@/api/backend'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'YimaiMiniappConfig' })

  /** 与小程序端共享的配置结构（后端 GET/PUT /miniapp/config 的 data） */
  interface VenueImage {
    src: string
    caption: string
    /** 仅前端用：预览加载失败标记，不参与提交 */
    broken?: boolean
  }
  interface VenueConfig {
    key: string
    sub: string
    address: string
    phone: string
    latitude: number | null
    longitude: number | null
    channelsUser: string
    channelsLive: string
    images: VenueImage[]
  }
  interface MiniappConfig {
    venues: VenueConfig[]
    updatedAt: string
  }

  const MAX_IMAGES = 3
  /**
   * 坐标合理范围：与后端 `MiniappConfigController::update` 的校验逐字对齐
   * （纬度 between:12,55、经度 between:70,140）。前端先拦一遍，避免用户填完才吃 422。
   */
  const LAT_RANGE = [12, 55] as const
  const LNG_RANGE = [70, 140] as const

  /** 演示模式（VITE_USE_BACKEND=false）没有后端数据时的占位门店：与全站一致的两家店 */
  const DEMO_VENUES = ['绿地店', '东部店']

  const loading = ref(false)
  const saving = ref(false)
  const venues = ref<VenueConfig[]>([])
  const activeKey = ref('')
  const updatedAt = ref('')
  const demoNotice = ref('')
  const serverErrors = ref<string[]>([])
  /** 错误框标题：422 是「N 项校验」，403 等不是校验错，不能套同一句话 */
  const errorTitle = ref('保存被后端拒绝')

  /** 字段名 → 中文，用于把后端的 `errors` 键翻译成人话（键形如 venues.0.latitude） */
  const FIELD_LABELS: Record<string, string> = {
    venues: '门店',
    key: '门店键',
    sub: '副标题',
    address: '地址',
    phone: '电话',
    latitude: '纬度',
    longitude: '经度',
    channelsUser: '视频号',
    channelsLive: '直播 ID',
    images: '引导图',
    src: '图片地址',
    caption: '图片说明'
  }

  function fieldLabel(key: string): string {
    const parts = key.split('.')
    const numIdx = parts.findIndex((p) => /^\d+$/.test(p))
    const venueName = numIdx >= 0 ? (venues.value[Number(parts[numIdx])]?.key ?? '') : ''
    const last = parts[parts.length - 1]
    const name = FIELD_LABELS[last] ?? last
    return [venueName, name].filter(Boolean).join(' · ')
  }

  function normalize(raw: Partial<VenueConfig> & { key?: string }): VenueConfig {
    return {
      key: String(raw.key ?? ''),
      sub: String(raw.sub ?? ''),
      address: String(raw.address ?? ''),
      phone: String(raw.phone ?? ''),
      // 后端可能给字符串数字；也允许是 null（未填坐标）
      latitude: toNumberOrNull(raw.latitude),
      longitude: toNumberOrNull(raw.longitude),
      channelsUser: String(raw.channelsUser ?? ''),
      channelsLive: String(raw.channelsLive ?? ''),
      images: Array.isArray(raw.images)
        ? raw.images.map((i) => ({ src: String(i?.src ?? ''), caption: String(i?.caption ?? '') }))
        : []
    }
  }

  function toNumberOrNull(v: unknown): number | null {
    if (v === null || v === undefined || v === '') return null
    const n = Number(v)
    return Number.isFinite(n) ? n : null
  }

  /**
   * 引导图地址白名单校验 —— 与后端 `venues.*.images.*.src` 的自定义校验同一条规则：
   * 只放行「站内路径（`/` 开头且非 `//`）」与 `https://`；`javascript:` / `data:` /
   * `http:` / 协议相对 `//host` 一律拒绝。前端先拦是为了「填错立刻看到」，
   * 而不是等提交后 422 再回头找是哪一张写错了。
   */
  function srcError(src: string): string | null {
    const v = src.trim()
    if (!v) return null // 空行在保存时会被丢弃，不算错
    const isStationPath = v.startsWith('/') && !v.startsWith('//')
    const isHttps = /^https:\/\/[^\s]+$/i.test(v)
    if (!isStationPath && !isHttps) {
      return '只允许站内路径（/ 开头）或 https:// 外链'
    }
    return null
  }

  async function load() {
    loading.value = true
    serverErrors.value = []
    try {
      if (!USE_BACKEND) {
        // 演示模式：没有后端就没有真实门店配置，只铺结构供预览，保存不会生效
        venues.value = DEMO_VENUES.map((key) => normalize({ key }))
        demoNotice.value =
          '演示模式：当前没有连接 Laravel 后端，下面的门店配置是空白占位，保存不会生效。'
        updatedAt.value = ''
      } else {
        const data = await apiGet<MiniappConfig>('/miniapp/config')
        venues.value = (data?.venues ?? []).map((v) => normalize(v))
        updatedAt.value = data?.updatedAt ?? ''
        demoNotice.value = ''
      }
      activeKey.value = venues.value[0]?.key ?? ''
    } catch (e) {
      venues.value = []
      showApiError(e, '读取小程序设置')
    } finally {
      loading.value = false
    }
  }

  function addImage(v: VenueConfig) {
    if (v.images.length >= MAX_IMAGES) return
    v.images.push({ src: '', caption: '' })
  }

  function removeImage(v: VenueConfig, idx: number) {
    v.images.splice(idx, 1)
  }

  /**
   * 把后端 422 的字段级错误摊平成可读文案。
   *
   * Laravel 的 `ValidationException` 返回 `{ message, errors: { 'venues.0.latitude': ['...'] } }`，
   * 而 `unwrap()` 只把 `message` 抛出来（通常是第一条，未必对应用户刚改的字段）。
   * 对馆主来说「纬度超出范围」才是有效信息，所以这里直接读 axios 错误的 `response.data.errors`，
   * 逐字段翻译后展示，**不合并成一句通用的「保存失败」**。
   */
  function collectValidationErrors(e: unknown): string[] {
    const data = (e as { response?: { data?: { errors?: Record<string, string[] | string> } } })
      ?.response?.data
    const errors = data?.errors
    if (!errors || typeof errors !== 'object') return []
    const out: string[] = []
    for (const [field, msg] of Object.entries(errors)) {
      const text = Array.isArray(msg) ? msg.join('；') : String(msg)
      out.push(`${fieldLabel(field)}：${text}`)
    }
    return out
  }

  async function save() {
    if (!USE_BACKEND) {
      ElMessage.warning('演示模式下没有后端，保存不会生效')
      return
    }
    // 提交前先跑一遍本地校验：图片地址格式是唯一一个「后端会拒、但用户很难自己发现」的错
    const formatErrors: string[] = []
    for (const v of venues.value) {
      v.images.forEach((img, idx) => {
        const err = srcError(img.src)
        if (err) formatErrors.push(`${v.key} · 第 ${idx + 1} 张引导图：${err}`)
      })
    }
    if (formatErrors.length) {
      errorTitle.value = `有 ${formatErrors.length} 处图片地址不合法，未提交`
      serverErrors.value = formatErrors
      ElMessage.error('图片地址格式不对，详见上方红框（未提交）')
      return
    }

    saving.value = true
    serverErrors.value = []
    try {
      // 注意：**所有字段都要显式发出去，包括空串**。
      // 后端是「合并语义」——用 `array_key_exists` 判存在：传了 `''` 才会清空，
      // 不传则保留旧值。少发一个空串，用户会看到「保存成功但清不掉」。
      // 经纬度用 null 表示「未配置」（后端 $coord() 把 null/''/非数字都落成 null）。
      const payload = {
        venues: venues.value.map((v) => ({
          key: v.key,
          sub: v.sub,
          address: v.address,
          phone: v.phone,
          latitude: v.latitude,
          longitude: v.longitude,
          channelsUser: v.channelsUser,
          channelsLive: v.channelsLive,
          images: v.images
            .filter((i) => i.src.trim())
            .map((i) => ({ src: i.src.trim(), caption: i.caption }))
        }))
      }
      const res = await apiPut<MiniappConfig>('/miniapp/config', payload)
      if (res?.updatedAt) {
        updatedAt.value = res.updatedAt
      } else {
        // 后端若没回 updatedAt，重新读一次，保证页面显示的是服务端真值
        const latest = await apiGet<MiniappConfig>('/miniapp/config')
        updatedAt.value = latest?.updatedAt ?? ''
      }
      if (Array.isArray(res?.venues)) venues.value = res.venues.map((v) => normalize(v))
      ElMessage.success(`已保存，小程序即刻生效（${updatedAt.value || '已更新'}）`)
    } catch (e) {
      const fieldErrors = collectValidationErrors(e)
      if (fieldErrors.length) {
        // 422：把后端逐字段文案摊在页面上（ElAlert 列表），同时给一条 toast 指路
        serverErrors.value = fieldErrors
        errorTitle.value = `保存被后端拒绝（${fieldErrors.length} 项校验未通过）`
        ElMessage.error(`保存失败：${fieldErrors.length} 项校验未通过，详见上方红框`)
      } else {
        // 403 / 401 / 网络失败：交给统一提示（403 说无权限、401 静默、其余说失败原因）
        showApiError(e, '保存小程序设置')
        const { status } = toHttpError(e)
        if (status === 403) {
          errorTitle.value = '没有修改权限'
          serverErrors.value = ['当前账号没有修改小程序设置的权限（仅馆主可改）']
        }
      }
    } finally {
      saving.value = false
    }
  }

  onMounted(load)
</script>

<style scoped>
  .field-tip {
    margin-top: 2px;
    font-size: 12px;
    line-height: 1.6;
    color: var(--el-text-color-secondary);
  }

  .img-block {
    padding-bottom: 8px;
    margin-bottom: 8px;
    border-bottom: 1px dashed var(--el-border-color-lighter);
  }

  .img-row {
    display: flex;
    gap: 8px;
    align-items: center;
  }

  .img-row :deep(.el-input:first-child) {
    flex: 0 0 44%;
  }

  .img-row :deep(.el-input) {
    flex: 1;
  }

  .img-check {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-top: 6px;
  }

  .thumb {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 72px;
    height: 48px;
    overflow: hidden;
    font-size: 11px;
    color: var(--el-text-color-placeholder);
    background: var(--el-fill-color-light);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 4px;
  }

  .thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
</style>
