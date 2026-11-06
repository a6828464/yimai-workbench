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
          小程序「到店指引」页显示的门店副标题、地址、电话、坐标、门店视频、三种出行路线与引导图，
          以及首页弹窗，全部来自这份配置。改完保存即可生效，不需要发版。
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
                不会把顾客导到错误位置。合理范围：纬度 {{ LAT_RANGE[0] }}–{{ LAT_RANGE[1] }}、经度
                {{ LNG_RANGE[0] }}–{{ LNG_RANGE[1] }}（与后端同一套校验）。
              </div>
            </ElFormItem>

            <ElFormItem label="视频号">
              <ElInput
                v-model="v.channelsUser"
                maxlength="120"
                placeholder="可留空，如：一麦瑜伽"
              />
              <div class="field-tip">
                门店视频号 ID，顾客端用于「看门店视频 / 直播」入口；没有就留空。
              </div>
            </ElFormItem>

            <ElFormItem label="直播 ID">
              <ElInput
                v-model="v.channelsLive"
                maxlength="120"
                placeholder="可留空，如：live_xxxx"
              />
              <div class="field-tip">视频号直播间 ID，留空则顾客端不显示直播入口。</div>
            </ElFormItem>

            <!-- ===== 门店视频（直链，不是视频号） ===== -->
            <ElFormItem label="门店视频">
              <div class="w-full">
                <ElInput
                  v-model="v.videoUrl"
                  maxlength="200"
                  placeholder="/static/venue.mp4 或 https://.../venue.mp4"
                />
                <div v-if="urlError(v.videoUrl)" class="c-red text-xs">
                  ✕ {{ urlError(v.videoUrl) }}
                </div>
                <div class="field-tip">
                  这里填的是<b>可直接播放的视频文件直链（mp4）</b>，不是视频号链接 ——
                  小程序用原生播放器播这个地址；视频号请填上面的「视频号 / 直播 ID」。
                </div>
              </div>
            </ElFormItem>

            <!-- ===== 门店引导图 ===== -->
            <ElFormItem label="门店引导图">
              <div class="w-full">
                <div v-for="(img, idx) in v.images" :key="idx" class="img-block">
                  <div class="img-row">
                    <ElInput
                      v-model="img.src"
                      maxlength="200"
                      placeholder="/static/hero.jpg 或 https://..."
                      @input="img.broken = false"
                    />
                    <label class="upload-label" :class="{ 'is-busy': img.uploading }">
                      <input
                        type="file"
                        :accept="UPLOAD_ACCEPT"
                        hidden
                        :disabled="!!img.uploading"
                        @change="onPickImage($event, img)"
                      />
                      {{ img.uploading ? '上传中…' : '上传' }}
                    </label>
                    <ElInput v-model="img.caption" maxlength="120" placeholder="说明" />
                    <ElButton link type="danger" @click="removeImage(v.images, idx)">删除</ElButton>
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
                    <span v-if="urlError(img.src)" class="c-red text-xs"
                      >✕ {{ urlError(img.src) }}</span
                    >
                    <span v-else-if="img.broken" class="c-red text-xs"
                      >✕ 这张图加载不出来（仍可保存）</span
                    >
                    <span v-else-if="img.src" class="text-xs text-gray-400">✓ 可预览</span>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <ElButton :disabled="v.images.length >= IMAGE_LIMIT" @click="addImage(v.images)">
                    新增引导图
                  </ElButton>
                  <span class="text-xs text-gray-400">
                    已加 {{ v.images.length }}/{{ IMAGE_LIMIT }}
                    <template v-if="v.images.length >= IMAGE_LIMIT">
                      （已达上限，删一张才能再加）
                    </template>
                  </span>
                </div>
              </div>
            </ElFormItem>

            <!-- ===== 到店路线：三组互相独立 ===== -->
            <ElFormItem label="到店路线">
              <div class="w-full">
                <ElCollapse v-model="openRoutes[v.key]" class="route-collapse">
                  <ElCollapseItem
                    v-for="rk in ROUTE_KEYS"
                    :key="rk"
                    :name="rk"
                    :title="ROUTE_LABELS[rk]"
                  >
                    <ElForm label-width="88px" class="pt-1">
                      <!-- 文字说明放该组最上：它就是这条路线的小标题/导语 -->
                      <ElFormItem label="文字说明">
                        <div class="w-full">
                          <ElInput
                            v-model="v.routes[rk].note"
                            type="textarea"
                            :rows="3"
                            maxlength="200"
                            show-word-limit
                            :placeholder="`例：${ROUTE_LABELS[rk]}请从东门进，地库 B1 有 2 小时免费停车`"
                          />
                          <span class="text-xs text-gray-400">
                            已输入 {{ v.routes[rk].note.length }}/200 · 顾客点开「{{
                              ROUTE_LABELS[rk]
                            }}」会先看到这段话
                          </span>
                        </div>
                      </ElFormItem>
                      <ElFormItem label="视频链接">
                        <div class="w-full">
                          <ElInput
                            v-model="v.routes[rk].videoUrl"
                            maxlength="200"
                            :placeholder="`${ROUTE_LABELS[rk]}的视频直链（mp4）`"
                          />
                          <div v-if="urlError(v.routes[rk].videoUrl)" class="c-red text-xs">
                            ✕ {{ urlError(v.routes[rk].videoUrl) }}
                          </div>
                        </div>
                      </ElFormItem>
                      <ElFormItem label="路线图">
                        <div class="w-full">
                          <div
                            v-for="(img, idx) in v.routes[rk].images"
                            :key="idx"
                            class="img-block"
                          >
                            <div class="img-row">
                              <ElInput
                                v-model="img.src"
                                maxlength="200"
                                placeholder="/static/drive-1.jpg 或 https://..."
                                @input="img.broken = false"
                              />
                              <label class="upload-label" :class="{ 'is-busy': img.uploading }">
                                <input
                                  type="file"
                                  :accept="UPLOAD_ACCEPT"
                                  hidden
                                  :disabled="!!img.uploading"
                                  @change="onPickImage($event, img)"
                                />
                                {{ img.uploading ? '上传中…' : '上传' }}
                              </label>
                              <ElInput v-model="img.caption" maxlength="120" placeholder="说明" />
                              <ElButton
                                link
                                type="danger"
                                @click="removeImage(v.routes[rk].images, idx)"
                              >
                                删除
                              </ElButton>
                            </div>
                            <div class="img-check">
                              <div class="thumb">
                                <img
                                  v-if="img.src && !img.broken"
                                  :src="img.src"
                                  alt="路线图预览"
                                  @error="img.broken = true"
                                  @load="img.broken = false"
                                />
                                <span v-else class="thumb-ph">{{
                                  img.src ? '地址不可用' : '未填地址'
                                }}</span>
                              </div>
                              <span v-if="urlError(img.src)" class="c-red text-xs"
                                >✕ {{ urlError(img.src) }}</span
                              >
                              <span v-else-if="img.broken" class="c-red text-xs"
                                >✕ 这张图加载不出来（仍可保存）</span
                              >
                              <span v-else-if="img.src" class="text-xs text-gray-400"
                                >✓ 可预览</span
                              >
                            </div>
                          </div>
                          <div class="flex items-center gap-2">
                            <ElButton
                              :disabled="v.routes[rk].images.length >= IMAGE_LIMIT"
                              @click="addImage(v.routes[rk].images)"
                            >
                              新增路线图
                            </ElButton>
                            <span class="text-xs text-gray-400">
                              已加 {{ v.routes[rk].images.length }}/{{ IMAGE_LIMIT }}
                              <template v-if="v.routes[rk].images.length >= IMAGE_LIMIT">
                                （已达上限，删一张才能再加）
                              </template>
                            </span>
                          </div>
                          <div class="field-tip">
                            这三组<b>互相独立</b>：只改「{{
                              ROUTE_LABELS[rk]
                            }}」不会动另外两种出行方式。
                          </div>
                        </div>
                      </ElFormItem>
                    </ElForm>
                  </ElCollapseItem>
                </ElCollapse>
              </div>
            </ElFormItem>
          </ElForm>
        </ElTabPane>
      </ElTabs>

      <ElEmpty
        v-else-if="!loading"
        description="没有读到门店配置（后端 /miniapp/config 未就绪或返回空）"
      />
    </ElCard>

    <!-- ===== 首页弹窗（全局） ===== -->
    <ElCard shadow="never" class="mb-3">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">首页弹窗</span>
          <div class="flex items-center gap-2">
            <span class="text-xs text-gray-400">{{ popup.enabled ? '已开启' : '已关闭' }}</span>
            <ElSwitch v-model="popup.enabled" />
          </div>
        </div>
      </template>

      <ElAlert
        type="info"
        :closable="false"
        show-icon
        class="mb-3"
        title="关闭时下面所有字段置灰但仍可见"
      >
        <div class="text-xs leading-5">
          通常做法：先把标题、内容、图片填好，再打开开关上线。开关关闭时小程序不弹窗，已填内容不会丢。
        </div>
      </ElAlert>

      <!-- disabled 只禁用交互，不隐藏：馆主要先填好再开开关 -->
      <ElForm label-width="104px" :disabled="!popup.enabled">
        <ElFormItem label="标题">
          <ElInput
            v-model="popup.title"
            maxlength="40"
            show-word-limit
            placeholder="如：双十一体验课 9.9 元"
          />
          <div class="field-tip">弹窗顶部大字，建议 12 字以内；留空则只显示内容。</div>
        </ElFormItem>

        <ElFormItem label="内容">
          <ElInput
            v-model="popup.content"
            type="textarea"
            :rows="4"
            maxlength="500"
            show-word-limit
            placeholder="说明活动内容、时间、怎么报名"
          />
        </ElFormItem>

        <ElFormItem label="图片">
          <div class="w-full">
            <div class="img-row">
              <ElInput
                v-model="popup.imageUrl"
                maxlength="200"
                placeholder="/static/popup.jpg 或 https://..."
              />
              <label class="upload-label" :class="{ 'is-busy': popupUploading }">
                <input
                  type="file"
                  :accept="UPLOAD_ACCEPT"
                  hidden
                  :disabled="popupUploading"
                  @change="onPickPopupImage"
                />
                {{ popupUploading ? '上传中…' : '上传' }}
              </label>
            </div>
            <div class="img-check">
              <div class="thumb">
                <img
                  v-if="popup.imageUrl && !popupImageBroken"
                  :src="popup.imageUrl"
                  alt="弹窗图预览"
                  @error="popupImageBroken = true"
                  @load="popupImageBroken = false"
                />
                <span v-else class="thumb-ph">{{
                  popup.imageUrl ? '地址不可用' : '未填地址'
                }}</span>
              </div>
              <span v-if="urlError(popup.imageUrl)" class="c-red text-xs"
                >✕ {{ urlError(popup.imageUrl) }}</span
              >
              <span v-else-if="popupImageBroken" class="c-red text-xs"
                >✕ 这张图加载不出来（仍可保存）</span
              >
              <span v-else-if="popup.imageUrl" class="text-xs text-gray-400">✓ 可预览</span>
            </div>
          </div>
        </ElFormItem>

        <ElFormItem label="弹出频率">
          <ElRadioGroup v-model="popup.frequency">
            <ElRadio value="once">只显示一次（顾客看过就不再弹）</ElRadio>
            <ElRadio value="always">每次打开都显示</ElRadio>
          </ElRadioGroup>
          <div class="field-tip">「只显示一次」按顾客端本地记录判断，清缓存后会再弹一次。</div>
        </ElFormItem>
      </ElForm>
    </ElCard>

    <div class="flex items-center gap-2">
      <ElButton
        type="primary"
        :loading="saving"
        :disabled="!venues.length || anyUploading"
        @click="save"
      >
        保存
      </ElButton>
      <span v-if="anyUploading" class="text-xs c-orange">有图片正在上传，等传完再保存</span>
      <ElButton :disabled="loading" @click="load">放弃修改并重读</ElButton>
      <span class="text-xs text-gray-400">保存后立即对微信小程序生效，无需发版</span>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { apiGet, apiPut, showApiError, toHttpError, USE_BACKEND } from '@/api/backend'
  import {
    uploadMiniappImage,
    type MiniappConfig,
    type MiniappPopupConfig,
    type MiniappRouteConfig,
    type MiniappRouteKey,
    type MiniappVenueConfig,
    type MiniappVenueImage
  } from '@/api/yimai'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'YimaiMiniappConfig' })

  /**
   * 契约类型定义在 `@/api/yimai`（两端共享的唯一一份）；
   * 这里只派生「带预览失败标记」的 UI 类型，避免把接口结构在本页抄第二遍。
   */
  /** 契约类型 + 两个纯前端态：预览失败、正在上传（都不参与提交） */
  type VenueImage = MiniappVenueImage & { broken?: boolean; uploading?: boolean }
  type RouteKey = MiniappRouteKey
  type RouteConfig = Omit<MiniappRouteConfig, 'images'> & { images: VenueImage[] }
  type VenueConfig = Omit<MiniappVenueConfig, 'images' | 'routes'> & {
    images: VenueImage[]
    routes: Record<RouteKey, RouteConfig>
  }
  type PopupConfig = MiniappPopupConfig

  /** 图片组上限：门店引导图与三种出行方式的路线图都是这个数 */
  const IMAGE_LIMIT = 9
  const ROUTE_KEYS: RouteKey[] = ['drive', 'transit', 'walk']
  const ROUTE_LABELS: Record<RouteKey, string> = {
    drive: '开车出行',
    transit: '公共交通',
    walk: '步行前往'
  }
  /**
   * 坐标合理范围：与后端 `MiniappConfigController` 的校验对齐
   * （纬度 between:12,55、经度 between:70,140）。前端先拦一遍，避免填完才吃 422。
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
  const popup = reactive<PopupConfig>(emptyPopup())
  const popupImageBroken = ref(false)
  /** 每店默认展开哪一组路线（默认开车；其余折叠，避免 27 个输入框一屏） */
  const openRoutes = ref<Record<string, RouteKey[]>>({})

  function emptyPopup(): PopupConfig {
    return { enabled: false, title: '', content: '', imageUrl: '', frequency: 'once' }
  }

  /** 字段名 → 中文，用于把后端的 `errors` 键翻译成人话 */
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
    images: '图片',
    src: '图片地址',
    caption: '图片说明',
    videoUrl: '视频地址',
    routes: '到店路线',
    popup: '首页弹窗',
    enabled: '开关',
    title: '标题',
    content: '内容',
    imageUrl: '弹窗图片',
    frequency: '弹出频率'
  }

  /**
   * 把后端 `errors` 的键翻译成「门店名 · 出行方式 · 第 N 张 · 字段」。
   *
   * 形如 `venues.0.routes.drive.videoUrl` → 「绿地店 · 开车出行 · 视频地址」；
   * `venues.1.images.3.src` → 「东部店 · 第 4 张 · 图片地址」；`popup.title` → 「首页弹窗 · 标题」。
   */
  function fieldLabel(key: string): string {
    const parts = key.split('.')
    const segs: string[] = []

    if (parts[0] === 'popup') segs.push('首页弹窗')
    if (parts[0] === 'venues' && /^\d+$/.test(parts[1] ?? '')) {
      segs.push(venues.value[Number(parts[1])]?.key ?? '门店')
    }

    for (let i = 0; i < parts.length; i += 1) {
      const p = parts[i]
      const next = parts[i + 1]
      if (p === 'routes' && next && (ROUTE_KEYS as string[]).includes(next)) {
        segs.push(ROUTE_LABELS[next as RouteKey])
        i += 1
        continue
      }
      if (p === 'images' && next && /^\d+$/.test(next)) {
        segs.push(`第 ${Number(next) + 1} 张`)
        i += 1
      }
    }

    const last = parts[parts.length - 1]
    const name = FIELD_LABELS[last] ?? last
    if (!segs.includes(name)) segs.push(name)
    return segs.filter(Boolean).join(' · ')
  }

  function normalize(raw: Partial<VenueConfig> & { key?: string }): VenueConfig {
    const rawRoutes = (raw.routes ?? {}) as Partial<Record<RouteKey, Partial<RouteConfig>>>
    const routes = {} as Record<RouteKey, RouteConfig>
    for (const rk of ROUTE_KEYS) {
      const r = rawRoutes[rk]
      routes[rk] = {
        videoUrl: String(r?.videoUrl ?? ''),
        note: String(r?.note ?? ''),
        images: Array.isArray(r?.images)
          ? r!.images.map((i) => ({ src: String(i?.src ?? ''), caption: String(i?.caption ?? '') }))
          : []
      }
    }
    return {
      key: String(raw.key ?? ''),
      sub: String(raw.sub ?? ''),
      address: String(raw.address ?? ''),
      phone: String(raw.phone ?? ''),
      latitude: toNumberOrNull(raw.latitude),
      longitude: toNumberOrNull(raw.longitude),
      channelsUser: String(raw.channelsUser ?? ''),
      channelsLive: String(raw.channelsLive ?? ''),
      images: Array.isArray(raw.images)
        ? raw.images.map((i) => ({ src: String(i?.src ?? ''), caption: String(i?.caption ?? '') }))
        : [],
      videoUrl: String(raw.videoUrl ?? ''),
      routes
    }
  }

  function normalizePopup(raw?: Partial<PopupConfig> | null): PopupConfig {
    return {
      enabled: Boolean(raw?.enabled),
      title: String(raw?.title ?? ''),
      content: String(raw?.content ?? ''),
      imageUrl: String(raw?.imageUrl ?? ''),
      frequency: raw?.frequency === 'always' ? 'always' : 'once'
    }
  }

  function toNumberOrNull(v: unknown): number | null {
    if (v === null || v === undefined || v === '') return null
    const n = Number(v)
    return Number.isFinite(n) ? n : null
  }

  /**
   * URL 白名单校验 —— 视频直链与所有图片共用，与后端自定义校验同一条规则：
   * 只放行「站内路径（`/` 开头且非 `//`）」与 `https://`；`javascript:` / `data:` /
   * `http:` / 协议相对 `//host` 一律拒绝。前端先拦是为了「填错立刻看到」。
   */
  function urlError(url: string): string | null {
    const v = (url ?? '').trim()
    if (!v) return null // 空 = 不配置，不算错
    const isStationPath = v.startsWith('/') && !v.startsWith('//')
    const isHttps = /^https:\/\/[^\s]+$/i.test(v)
    if (!isStationPath && !isHttps) return '只允许站内路径（/ 开头）或 https:// 外链'
    return null
  }

  async function load() {
    loading.value = true
    serverErrors.value = []
    try {
      if (!USE_BACKEND) {
        venues.value = DEMO_VENUES.map((key) => normalize({ key }))
        Object.assign(popup, emptyPopup())
        demoNotice.value =
          '演示模式：当前没有连接 Laravel 后端，下面的配置是空白占位，保存不会生效。'
        updatedAt.value = ''
      } else {
        const data = await apiGet<MiniappConfig>('/miniapp/config')
        venues.value = (data?.venues ?? []).map((v) => normalize(v))
        Object.assign(popup, normalizePopup(data?.popup))
        updatedAt.value = data?.updatedAt ?? ''
        demoNotice.value = ''
      }
      activeKey.value = venues.value[0]?.key ?? ''
      openRoutes.value = Object.fromEntries(
        venues.value.map((v) => [v.key, ['drive'] as RouteKey[]])
      )
      popupImageBroken.value = false
    } catch (e) {
      venues.value = []
      showApiError(e, '读取小程序设置')
    } finally {
      loading.value = false
    }
  }

  function addImage(list: VenueImage[]) {
    if (list.length >= IMAGE_LIMIT) return
    list.push({ src: '', caption: '' })
  }

  function removeImage(list: VenueImage[], idx: number) {
    list.splice(idx, 1)
  }

  /* ------------------------------------------------------------------ *
   * 图片上传（与「手填路径」并存，互不替代）
   *
   * 两条通道的定位：
   * - 上传：馆主手上就有图，选完立刻得到站内地址，不用去别处找链接；
   * - 手填：上传接口挂了 / 图已经在图床 / 想引用小程序包内静态资源（/static/...）时用。
   * 所以失败时**只 toast**，输入框始终可编辑 —— 上传坏了不能导致完全没法配图。
   * ------------------------------------------------------------------ */

  /** 与 BE4 同一条预校验：按真实 MIME 判型 + 5MB 上限（先拦一道，别等 422） */
  const UPLOAD_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
  const UPLOAD_ACCEPT = UPLOAD_MIMES.join(',')
  const UPLOAD_MAX_BYTES = 5 * 1024 * 1024
  /** 弹窗图单独一个上传态（它是全区的，不属于任何一张 images[]） */
  const popupUploading = ref(false)

  /** 有任何一张图正在上传：此时禁用「保存」，避免把旧 src 存成最终值 */
  const anyUploading = computed(
    () =>
      popupUploading.value ||
      venues.value.some(
        (v) =>
          v.images.some((i) => i.uploading) ||
          ROUTE_KEYS.some((rk) => v.routes[rk].images.some((i) => i.uploading))
      )
  )

  function checkImageFile(file: File): string | null {
    if (!UPLOAD_MIMES.includes(file.type)) {
      return `只支持 JPG / PNG / WebP / GIF，当前是 ${file.type || '未知类型'}`
    }
    if (file.size > UPLOAD_MAX_BYTES) {
      return `图片不能超过 5MB，当前 ${(file.size / 1024 / 1024).toFixed(1)}MB`
    }
    return null
  }

  async function uploadInto(target: VenueImage, file: File) {
    const err = checkImageFile(file)
    if (err) {
      ElMessage.error(err)
      return
    }
    target.uploading = true
    try {
      const url = await uploadMiniappImage(file)
      target.src = url
      target.broken = false
      ElMessage.success('图片已上传，保存配置后生效')
    } catch (e) {
      // 后端 422（超大/类型不支持）会由 showApiError 透出中文文案；网络失败说通用原因
      showApiError(e, '上传图片')
    } finally {
      target.uploading = false
    }
  }

  function onPickImage(ev: Event, target: VenueImage) {
    const input = ev.target as HTMLInputElement
    const file = input.files?.[0]
    // 清空 value，否则连续选同一个文件不会触发 change
    input.value = ''
    if (file) void uploadInto(target, file)
  }

  async function onPickPopupImage(ev: Event) {
    const input = ev.target as HTMLInputElement
    const file = input.files?.[0]
    input.value = ''
    if (!file) return
    const err = checkImageFile(file)
    if (err) {
      ElMessage.error(err)
      return
    }
    popupUploading.value = true
    try {
      popup.imageUrl = await uploadMiniappImage(file)
      popupImageBroken.value = false
      ElMessage.success('图片已上传，保存配置后生效')
    } catch (e) {
      showApiError(e, '上传图片')
    } finally {
      popupUploading.value = false
    }
  }

  /**
   * 把后端 422 的字段级错误摊平成可读文案。
   *
   * Laravel 返回 `{ message, errors: { 'venues.0.routes.drive.videoUrl': ['...'] } }`，
   * 而 `unwrap()` 只把 `message` 抛出来（通常是第一条，未必对应用户刚改的字段）。
   * 所以这里直接读 axios 错误的 `response.data.errors`，逐字段翻译后展示，
   * **不合并成一句通用的「保存失败」**。
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

  /** 提交前本地校验：门店视频 / 三组路线视频 / 所有图片 / 弹窗图片 */
  function collectFormatErrors(): string[] {
    const out: string[] = []
    for (const v of venues.value) {
      const vErr = urlError(v.videoUrl)
      if (vErr) out.push(`${v.key} · 门店视频：${vErr}`)
      v.images.forEach((img, idx) => {
        const err = urlError(img.src)
        if (err) out.push(`${v.key} · 门店引导图第 ${idx + 1} 张：${err}`)
      })
      for (const rk of ROUTE_KEYS) {
        const rErr = urlError(v.routes[rk].videoUrl)
        if (rErr) out.push(`${v.key} · ${ROUTE_LABELS[rk]}视频：${rErr}`)
        v.routes[rk].images.forEach((img, idx) => {
          const err = urlError(img.src)
          if (err) out.push(`${v.key} · ${ROUTE_LABELS[rk]}第 ${idx + 1} 张图：${err}`)
        })
      }
    }
    const pErr = urlError(popup.imageUrl)
    if (pErr) out.push(`首页弹窗 · 图片：${pErr}`)
    return out
  }

  async function save() {
    if (!USE_BACKEND) {
      ElMessage.warning('演示模式下没有后端，保存不会生效')
      return
    }
    const formatErrors = collectFormatErrors()
    if (formatErrors.length) {
      errorTitle.value = `有 ${formatErrors.length} 处地址不合法，未提交`
      serverErrors.value = formatErrors
      ElMessage.error('地址格式不对，详见上方红框（未提交）')
      return
    }

    saving.value = true
    serverErrors.value = []
    try {
      // 提交策略：**每次都提交完整结构**（两家店 + 三种路线 + 弹窗）。
      //
      // 后端 PUT 是合并语义（`array_key_exists` 判存在：传 `''` 才清空，不传则保留旧值）。
      // `routes` 是嵌套结构 —— 这里不依赖 BE2 的合并粒度：
      //   · 若它做递归合并：传全量三组 = 原样写回，安全；
      //   · 若它整体替换 routes：传全量三组，三组都不会丢，同样安全。
      // 所以「每次传全量」在两种实现下都正确。popup 与 venues 一起提交，
      // 改弹窗不会清门店、改门店不会清弹窗。
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
          videoUrl: v.videoUrl,
          images: v.images
            .filter((i) => i.src.trim())
            .map((i) => ({ src: i.src.trim(), caption: i.caption })),
          routes: Object.fromEntries(
            ROUTE_KEYS.map((rk) => [
              rk,
              {
                videoUrl: v.routes[rk].videoUrl,
                note: v.routes[rk].note,
                images: v.routes[rk].images
                  .filter((i) => i.src.trim())
                  .map((i) => ({ src: i.src.trim(), caption: i.caption }))
              }
            ])
          )
        })),
        popup: {
          enabled: popup.enabled,
          title: popup.title,
          content: popup.content,
          imageUrl: popup.imageUrl,
          frequency: popup.frequency
        }
      }
      const res = await apiPut<MiniappConfig>('/miniapp/config', payload)
      if (res?.updatedAt) {
        updatedAt.value = res.updatedAt
      } else {
        const latest = await apiGet<MiniappConfig>('/miniapp/config')
        updatedAt.value = latest?.updatedAt ?? ''
      }
      // 用服务端返回的真值回填（后端可能做了归一：key 顺序、图片裁剪）
      if (Array.isArray(res?.venues)) venues.value = res.venues.map((v) => normalize(v))
      if (res?.popup) Object.assign(popup, normalizePopup(res.popup))
      ElMessage.success(`已保存，小程序即刻生效（${updatedAt.value || '已更新'}）`)
    } catch (e) {
      const fieldErrors = collectValidationErrors(e)
      if (fieldErrors.length) {
        serverErrors.value = fieldErrors
        errorTitle.value = `保存被后端拒绝（${fieldErrors.length} 项校验未通过）`
        ElMessage.error(`保存失败：${fieldErrors.length} 项校验未通过，详见上方红框`)
      } else {
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
    flex: 0 0 72px;
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

  /* 上传按钮：用 label 包隐藏的 file input，点它即开文件选择框 */
  .upload-label {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    height: 32px;
    padding: 0 12px;
    font-size: 12px;
    color: var(--el-color-primary);
    cursor: pointer;
    user-select: none;
    background: var(--el-fill-color-blank);
    border: 1px solid var(--el-color-primary-light-5);
    border-radius: var(--el-border-radius-base);
    transition:
      color 0.15s,
      border-color 0.15s;
  }

  .upload-label:hover {
    border-color: var(--el-color-primary);
  }

  .upload-label.is-busy {
    color: var(--el-text-color-placeholder);
    cursor: not-allowed;
    border-color: var(--el-border-color);
  }

  .route-collapse :deep(.el-collapse-item__content) {
    padding-bottom: 4px;
  }
</style>
