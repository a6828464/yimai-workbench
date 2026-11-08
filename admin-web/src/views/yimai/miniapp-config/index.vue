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
                视频号用户名，顾客端用于「看视频号主页」入口；没有就留空。
              </div>
            </ElFormItem>

            <ElFormItem label="视频号视频 ID">
              <ElInput
                v-model="v.channelsLive"
                maxlength="120"
                placeholder="可留空，如：live_xxxx"
              />
              <div class="field-tip"
                >在视频号里点「复制视频
                ID」拿到的那串，填进来顾客端就能<b>内嵌播放</b>这条视频（不是直播间
                ID）。留空则不显示。</div
              >
            </ElFormItem>

            <!-- ===== 门店视频（直链，不是视频号） ===== -->
            <ElFormItem label="门店视频">
              <div class="w-full">
                <ElInput
                  v-model="v.videoUrl"
                  maxlength="200"
                  placeholder="/static/venue.mp4 或 https://.../venue.mp4"
                />
                <div v-if="videoRefError(v.videoUrl)" class="c-red text-xs">
                  ✕ {{ videoRefError(v.videoUrl) }}
                </div>
                <div class="field-tip">
                  这里填的是<b>可直接播放的视频文件直链（mp4）</b>，不是视频号链接 —— 填 mp4
                  直链用原生播放器播；<b>也可以直接填视频号视频 ID</b>，会改用它内嵌播放。
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
                      <ElFormItem label="视频直链">
                        <div class="w-full">
                          <ElInput
                            v-model="v.routes[rk].videoUrl"
                            maxlength="200"
                            :placeholder="`${ROUTE_LABELS[rk]}：https://.../route.mp4（可留空）`"
                          />
                          <div v-if="videoRefError(v.routes[rk].videoUrl)" class="c-red text-xs">
                            ✕ {{ videoRefError(v.routes[rk].videoUrl) }}
                          </div>
                          <div class="field-tip">
                            填<b>可直接播放的 mp4 / m3u8 文件直链</b>或站内路径；没有就留空。
                          </div>
                        </div>
                      </ElFormItem>
                      <ElFormItem label="视频号 ID">
                        <div class="w-full">
                          <ElInput
                            v-model="v.routes[rk].finderVideo"
                            maxlength="200"
                            placeholder="在视频号里点「复制视频 ID」粘进来（可留空）"
                          />
                          <div v-if="videoRefError(v.routes[rk].finderVideo)" class="c-red text-xs">
                            ✕ {{ videoRefError(v.routes[rk].finderVideo) }}
                          </div>
                          <div class="field-tip">
                            填视频号<b>视频 ID</b>（export/UzFf… 那串），顾客端直接内嵌播放；
                            与上面的直链各填各的，<b>两个都填时优先播视频号视频</b>。
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

    <!-- ===== 小程序主题（全局外观；切换只改本地状态，点保存才生效） ===== -->
    <ElCard shadow="never" class="mb-3">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">小程序主题</span>
          <div class="flex items-center gap-2">
            <ElTag
              v-if="themeDirty && !themeUnsupported"
              type="warning"
              size="small"
              effect="plain"
            >
              未保存
            </ElTag>
            <span class="text-xs text-gray-400">切换只是选中，点页面底部「保存」才生效</span>
          </div>
        </div>
      </template>

      <ElAlert
        v-if="themeUnsupported"
        type="warning"
        :closable="false"
        show-icon
        class="mb-3"
        title="后端还没支持主题（配置里没有 theme 字段），这里按「编辑」只读展示"
      >
        <div class="text-xs leading-5">
          BE7 部署后点右上角「重新读取」即可切换；在此之前切换不会被保存，所以这里先禁用。
        </div>
      </ElAlert>

      <!-- 存量 classic（BE7 之前的老配置）：静默升级为 editorial，只给一句轻提示 -->
      <ElAlert
        v-if="legacyThemeNotice"
        type="info"
        :closable="false"
        show-icon
        class="mb-3"
        title="经典主题已停用，已切换到编辑主题"
      >
        <div class="text-xs leading-5">
          你这套配置里存的还是旧的 classic。页面先按「编辑」展示，点保存后会写成
          editorial；不保存也不会报错。
        </div>
      </ElAlert>

      <div class="theme-grid">
        <button
          v-for="t in THEME_OPTIONS"
          :key="t.value"
          type="button"
          class="theme-card"
          :class="{ 'is-active': theme === t.value, 'is-disabled': themeUnsupported }"
          :disabled="themeUnsupported"
          :aria-pressed="theme === t.value"
          @click="selectTheme(t.value)"
        >
          <span v-if="theme === t.value" class="theme-card__check">✓</span>
          <span class="theme-card__label">{{ t.label }}</span>
          <span class="theme-card__desc">{{ t.desc }}</span>

          <!--
            示意缩略图用 CSS 画，不引用任何图片资源 ——
            真截图会随小程序改版过期，CSS 示意图不会。
            两张图靠「暖白圆角无衬线 / 米灰直角衬线」区分，一眼可辨。
          -->
          <span class="theme-phone" :class="`is-${t.value}`" aria-hidden="true">
            <template v-if="t.value === 'editorial'">
              <!-- 编辑：暖白底 + 沉浸大图 + 一条细线分区 + 整宽深色按钮 -->
              <span class="ph-hero ph-hero--warm"></span>
              <span class="ph-hair"></span>
              <span class="ph-line"></span>
              <span class="ph-line is-short"></span>
              <span class="ph-btn is-wide"></span>
            </template>
            <template v-else>
              <!-- 静奢东方：米灰底 + 顶部大图沉入米灰 + 衬线标题 + 发丝线 + 直角描边按钮 + 香槟金小标签 -->
              <span class="ph-hero ph-hero--mist">
                <span class="ph-gold">臻选</span>
              </span>
              <span class="ph-serif">到店指引</span>
              <span class="ph-hairline"></span>
              <span class="ph-line is-flat"></span>
              <span class="ph-line is-flat is-short"></span>
              <span class="ph-btn is-outline">预约</span>
            </template>
          </span>
        </button>
      </div>
    </ElCard>

    <!-- ===== 页面头图（首页背景 + 每家店顶图；都是「一张大图」，和门店实景组不是一回事） ===== -->
    <ElCard shadow="never" class="mb-3">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">页面头图</span>
          <span class="text-xs text-gray-400">
            首页背景 1 张 + 每家门店顶图各 1 张；留空会自动回落，不开天窗
          </span>
        </div>
      </template>

      <ElAlert
        type="info"
        :closable="false"
        show-icon
        class="mb-3"
        title="「门店顶图」和「门店实景 / 引导图」是两回事"
      >
        <div class="text-xs leading-5">
          <b>门店实景（本页「门店」区里的引导图）</b
          >：到店指引页里的一组图，可多张、每张带说明。<br />
          <b>门店顶图（这里）</b>：门店页最上方那张大图，只有 1 张、没有说明文字。
          <b>留空时自动取「门店实景」的第一张</b>，所以不填也有图；想让门店页开头更抓眼再单独指定。
        </div>
      </ElAlert>

      <!-- 首页背景图 -->
      <div class="hero-slot">
        <div class="hero-slot__head">
          <span class="hero-slot__title">首页背景图</span>
          <ElTag size="small" :type="homeHero ? 'success' : 'info'" effect="plain">
            {{ homeHero ? '已自定义' : '使用默认图' }}
          </ElTag>
        </div>
        <div class="img-row">
          <ElInput
            v-model="homeHero"
            maxlength="200"
            placeholder="/static/hero.jpg 或 https://..."
            @input="heroUiOf(HOME_HERO_KEY).broken = false"
          />
          <label class="upload-label" :class="{ 'is-busy': heroUiOf(HOME_HERO_KEY).uploading }">
            <input
              type="file"
              :accept="UPLOAD_ACCEPT"
              hidden
              :disabled="!!heroUiOf(HOME_HERO_KEY).uploading"
              @change="onPickHomeHero"
            />
            {{ heroUiOf(HOME_HERO_KEY).uploading ? '上传中…' : '上传' }}
          </label>
        </div>
        <div class="img-check">
          <div class="thumb thumb--wide">
            <img
              v-if="homeHero && !heroUiOf(HOME_HERO_KEY).broken"
              :src="homeHero"
              alt="首页背景预览"
              @error="heroUiOf(HOME_HERO_KEY).broken = true"
              @load="heroUiOf(HOME_HERO_KEY).broken = false"
            />
            <span v-else class="thumb-ph">{{
              homeHero ? '地址不可用' : '默认图 /static/hero.jpg'
            }}</span>
          </div>
          <div class="hero-slot__meta">
            <span v-if="urlError(homeHero)" class="c-red text-xs">✕ {{ urlError(homeHero) }}</span>
            <span v-else-if="heroUiOf(HOME_HERO_KEY).broken" class="c-red text-xs">
              ✕ 这张图加载不出来（仍可保存）
            </span>
            <div class="field-tip">
              留空则使用小程序包内默认图 <code>/static/hero.jpg</code>。首页是<b>全屏铺满</b>，
              建议竖版大图或 16:9 以上的横图，别用带文字的方图。
            </div>
          </div>
        </div>
      </div>

      <!-- 门店顶图（每家店一张） -->
      <div v-for="v in venues" :key="v.key" class="hero-slot">
        <div class="hero-slot__head">
          <span class="hero-slot__title">{{ v.key }} · 门店顶图</span>
          <ElTag size="small" :type="v.heroImage ? 'success' : 'info'" effect="plain">
            {{ v.heroImage ? '已自定义' : '回落门店实景第 1 张' }}
          </ElTag>
        </div>
        <div class="img-row">
          <ElInput
            v-model="v.heroImage"
            maxlength="200"
            placeholder="留空 = 自动用该店「门店实景」第一张"
            @input="heroUiOf(v.key).broken = false"
          />
          <label class="upload-label" :class="{ 'is-busy': heroUiOf(v.key).uploading }">
            <input
              type="file"
              :accept="UPLOAD_ACCEPT"
              hidden
              :disabled="!!heroUiOf(v.key).uploading"
              @change="onPickVenueHero($event, v)"
            />
            {{ heroUiOf(v.key).uploading ? '上传中…' : '上传' }}
          </label>
        </div>
        <div class="img-check">
          <div class="thumb thumb--wide">
            <img
              v-if="v.heroImage && !heroUiOf(v.key).broken"
              :src="v.heroImage"
              alt="门店顶图预览"
              @error="heroUiOf(v.key).broken = true"
              @load="heroUiOf(v.key).broken = false"
            />
            <span v-else class="thumb-ph">{{
              v.heroImage ? '地址不可用' : fallbackHeroOf(v) ? '回落实景图' : '暂无实景图'
            }}</span>
          </div>
          <div class="hero-slot__meta">
            <span v-if="urlError(v.heroImage)" class="c-red text-xs">
              ✕ {{ urlError(v.heroImage) }}
            </span>
            <span v-else-if="heroUiOf(v.key).broken" class="c-red text-xs">
              ✕ 这张图加载不出来（仍可保存）
            </span>
            <div class="field-tip">
              留空则自动使用该店「门店实景」的<b>第一张图</b>。 当前会回落到：<code>{{
                fallbackHeroOf(v) || '暂无实景图（小程序会用包内默认图）'
              }}</code>
              <template v-if="!fallbackHeroOf(v)">，建议先在上面「门店」区加一张实景图。</template>
            </div>
          </div>
        </div>
      </div>
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

    <!-- ===== 博客栏目（全局）：官网文章在到店指引页的展示 ===== -->
    <ElCard shadow="never" class="mb-3">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">博客栏目</span>
          <div class="flex items-center gap-2">
            <span class="text-xs text-gray-400">{{ blog.enabled ? '已开启' : '已关闭' }}</span>
            <ElSwitch v-model="blog.enabled" />
          </div>
        </div>
      </template>

      <ElAlert
        type="info"
        :closable="false"
        show-icon
        class="mb-3"
        title="官网文章自动同步，无需在这里维护内容"
      >
        <div class="text-xs leading-5">
          文章来自官网（www.nbyimai.com）WordPress：在官网后台发文或勾「置顶」即自动出现在小程序到店指引页；
          置顶文章排前面，其余名额用最新文章补足。这里只控制「显示几篇」和开关。
        </div>
      </ElAlert>

      <ElForm label-width="104px" :disabled="!blog.enabled">
        <ElFormItem label="显示篇数">
          <ElRadioGroup v-model="blog.count">
            <ElRadio v-for="n in 10" :key="n" :value="n">{{ n }}</ElRadio>
          </ElRadioGroup>
          <div class="field-tip">
            到店指引页「一麦 · 博客」栏目显示的文章数（置顶优先，最新补足）。改完保存约 5
            分钟内生效。
          </div>
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
      <span v-else-if="themeDirty && !themeUnsupported" class="text-xs c-orange">
        主题切换未保存（点「保存」后小程序才会换肤）
      </span>
      <ElButton :disabled="loading" @click="load">放弃修改并重读</ElButton>
      <span class="text-xs text-gray-400">保存后立即对微信小程序生效，无需发版</span>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { apiGet, apiPut, showApiError, toHttpError, USE_BACKEND } from '@/api/backend'
  import {
    uploadMiniappImage,
    type MiniappBlogConfig,
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
    /**
     * BE8 新增：门店顶图（空串 = 回落 `images[0]`）。
     * 公共类型 MiniappVenueConfig 还没跟上（本任务 inScope 只到本文件），先在这里局部扩展。
     */
    heroImage: string
  }
  type PopupConfig = MiniappPopupConfig
  type BlogConfig = MiniappBlogConfig

  /**
   * 小程序主题：只允许 editorial / oriental（BE7 起 classic 已停用）。
   * 主题是**全站视觉**，所以切换只写本地表单，必须点「保存」才 PUT —— 误触不能直接换脸。
   */
  type MiniappTheme = 'editorial' | 'oriental'
  const THEME_OPTIONS: { value: MiniappTheme; label: string; desc: string }[] = [
    {
      value: 'editorial',
      label: '编辑',
      desc: '暖白底（#fcfbf8），无卡片，靠细线与留白分区；主操作是整宽深色按钮。像一本干净的店刊。'
    },
    {
      value: 'oriental',
      label: '静奢东方',
      desc: '米灰底（#f2f4f2），衬线标题与发丝线分区，直角描边按钮 + 香槟金小字标签。更克制、更像画廊。'
    }
  ]

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
  /** 博客栏目（到店指引页「一麦 · 博客」）：开关 + 篇数（1-10） */
  const blog = reactive<BlogConfig>(emptyBlog())
  /** 当前选中的主题（表单态）；BE7 后的默认值 = editorial */
  const theme = ref<MiniappTheme>('editorial')
  /** 后端上一次确认生效的主题（用于判断「未保存」） */
  const savedTheme = ref<MiniappTheme>('editorial')
  /** 读到了已停用的 classic（BE7 之前的老配置）→ 静默升级为 editorial 并给一句提示 */
  const legacyThemeNotice = ref(false)
  /**
   * 后端未就绪（GET 里没有 theme 键）→ 主题区块降级为只读：
   * 仍显示两张卡片并默认选中经典，可读、不报错，但不让改（改了也存不进去）。
   */
  const themeUnsupported = ref(false)
  const themeDirty = computed(() => theme.value !== savedTheme.value)
  const popupImageBroken = ref(false)
  /** 每店默认展开哪一组路线（默认开车；其余折叠，避免 27 个输入框一屏） */
  const openRoutes = ref<Record<string, RouteKey[]>>({})

  function emptyPopup(): PopupConfig {
    return { enabled: false, title: '', content: '', imageUrl: '', frequency: 'once' }
  }

  function emptyBlog(): BlogConfig {
    return { enabled: true, count: 3 }
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
    channelsLive: '视频号视频 ID',
    images: '图片',
    src: '图片地址',
    caption: '图片说明',
    videoUrl: '视频地址',
    finderVideo: '视频号视频 ID',
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
        finderVideo: String(r?.finderVideo ?? ''),
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
      heroImage: String(raw.heroImage ?? ''),
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

  /** 博客配置归一：老后端没下发 blog 键 → 默认（开 + 3 篇）；count 脏值钳回 1..10 */
  function normalizeBlog(raw?: Partial<BlogConfig> | null): BlogConfig {
    const n = Number(raw?.count)
    return {
      enabled: raw?.enabled !== false, // 缺省按「开」处理（后端默认就是开）
      count: Number.isInteger(n) && n >= 1 && n <= 10 ? n : 3
    }
  }

  /**
   * 只认 oriental；其余（含已停用的 classic、未知字符串、undefined）一律落 editorial。
   * 这样即使 BE7 之后又出现新枚举值，页面也不会出现「空白选中态」。
   */
  function normalizeTheme(v: unknown): MiniappTheme {
    return v === 'oriental' ? 'oriental' : 'editorial'
  }

  /**
   * 选中主题（只改本地状态）。
   *
   * 后端未就绪时直接忽略 —— 虽然按钮已 `disabled`，但 disabled 只挡真实指针事件，
   * `dispatchEvent` 合成事件仍会触发监听器；这里再兜一层，避免"看起来能改、其实存不进去"。
   */
  function selectTheme(v: MiniappTheme) {
    if (themeUnsupported.value) return
    theme.value = v
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

  /**
   * 视频引用校验：URL 之外，还允许直接填**视频号视频 ID**。
   *
   * 馆主在视频号里点「复制视频 ID」拿到的是 `export/UzFfAgtgekIEAQAAAAAA...` 这种串，
   * 既不是站内路径也不是 https 直链，用 urlError 会直接被拦下（这就是之前
   * 「填了 ID 保存不了、小程序也没反应」的原因）。
   *
   * 只认两种形态：`export/<id>` 或纯字母数字串 —— 和后端 isAllowedVideoRef 同一套，
   * 不要放宽成「任意含斜杠」，否则相对路径 `relative/path.mp4` 也会混进来。
   */
  function videoRefError(ref: string | null | undefined): string | null {
    const v = (ref ?? '').trim()
    if (!v) return null
    if (!urlError(v)) return null
    if (/^(export\/)?[A-Za-z0-9_-]{10,}$/.test(v)) return null
    return '请填站内路径（/ 开头）、https 直链，或视频号视频 ID'
  }

  async function load() {
    loading.value = true
    serverErrors.value = []
    try {
      if (!USE_BACKEND) {
        venues.value = DEMO_VENUES.map((key) => normalize({ key }))
        homeHero.value = ''
        Object.assign(popup, emptyPopup())
        theme.value = 'editorial'
        savedTheme.value = 'editorial'
        themeUnsupported.value = true
        legacyThemeNotice.value = false
        demoNotice.value =
          '演示模式：当前没有连接 Laravel 后端，下面的配置是空白占位，保存不会生效。'
        updatedAt.value = ''
      } else {
        const data = await apiGet<MiniappConfig & { homeHero?: unknown }>('/miniapp/config')
        venues.value = (data?.venues ?? []).map((v) => normalize(v))
        homeHero.value = String(data?.homeHero ?? '')
        Object.assign(popup, normalizePopup(data?.popup))
        Object.assign(blog, normalizeBlog(data?.blog))
        // theme 由 BE5 新增；缺失时按经典只读展示，不当作错误
        const rawTheme = (data as (MiniappConfig & { theme?: unknown }) | null)?.theme
        if (typeof rawTheme === 'string') {
          // 存量 classic：静默升级为 editorial（不报错、不留空白选中态），只提示一句
          legacyThemeNotice.value = rawTheme === 'classic'
          theme.value = normalizeTheme(rawTheme)
          savedTheme.value = theme.value
          themeUnsupported.value = false
        } else {
          theme.value = 'editorial'
          savedTheme.value = 'editorial'
          themeUnsupported.value = true
          legacyThemeNotice.value = false
        }
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

  /* -------------------- 页面头图（BE8） -------------------- */
  /** 首页背景图（空串 = 用包内默认 /static/hero.jpg） */
  const homeHero = ref('')
  const HOME_HERO_KEY = '__home_hero__'
  /**
   * 头图位的 UI 态（上传中 / 预览失败），按 key 索引：HOME_HERO_KEY 与 门店 key 共用一张表。
   * 与数据分开存，避免把纯前端状态混进提交 payload。
   */
  const heroUi = reactive<Record<string, { uploading?: boolean; broken?: boolean }>>({})
  function heroUiOf(key: string) {
    if (!heroUi[key]) heroUi[key] = {}
    return heroUi[key]
  }
  /** 门店顶图留空时的回落目标 = 该店「门店实景」第一张有 src 的图 */
  function fallbackHeroOf(v: VenueConfig): string {
    return v.images.find((i) => i.src.trim())?.src?.trim() ?? ''
  }

  /** 有任何一张图正在上传：此时禁用「保存」，避免把旧 src 存成最终值 */
  const anyUploading = computed(
    () =>
      popupUploading.value ||
      Object.values(heroUi).some((u) => u.uploading) ||
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

  /**
   * 头图上传：与图片行共用同一套「预校验 + 上传 + 回填」逻辑，
   * 只是把结果写进对应的字符串字段（homeHero / venue.heroImage）。
   */
  async function uploadHeroInto(key: string, file: File, apply: (url: string) => void) {
    const err = checkImageFile(file)
    if (err) {
      ElMessage.error(err)
      return
    }
    const ui = heroUiOf(key)
    ui.uploading = true
    try {
      const url = await uploadMiniappImage(file)
      apply(url)
      ui.broken = false
      ElMessage.success('图片已上传，保存配置后生效')
    } catch (e) {
      showApiError(e, '上传图片')
    } finally {
      ui.uploading = false
    }
  }

  function onPickHomeHero(ev: Event) {
    const input = ev.target as HTMLInputElement
    const file = input.files?.[0]
    input.value = ''
    if (file) void uploadHeroInto(HOME_HERO_KEY, file, (url) => (homeHero.value = url))
  }

  function onPickVenueHero(ev: Event, v: VenueConfig) {
    const input = ev.target as HTMLInputElement
    const file = input.files?.[0]
    input.value = ''
    if (file) void uploadHeroInto(v.key, file, (url) => (v.heroImage = url))
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

  /** 提交前本地校验：门店视频 / 三组路线视频（直链 + 视频号 ID）/ 所有图片 / 弹窗图片 */
  function collectFormatErrors(): string[] {
    const out: string[] = []
    for (const v of venues.value) {
      // 门店视频 / 路线视频都是「视频引用」：除了站内路径与 https，还可能是视频号视频 ID，
      // 必须用 videoRefError（urlError 不认视频号 ID —— 2026-11-07「输入框不报错、保存才报错」即此因）
      const vErr = videoRefError(v.videoUrl)
      if (vErr) out.push(`${v.key} · 门店视频：${vErr}`)
      v.images.forEach((img, idx) => {
        const err = urlError(img.src)
        if (err) out.push(`${v.key} · 门店引导图第 ${idx + 1} 张：${err}`)
      })
      for (const rk of ROUTE_KEYS) {
        const rErr = videoRefError(v.routes[rk].videoUrl)
        if (rErr) out.push(`${v.key} · ${ROUTE_LABELS[rk]}视频直链：${rErr}`)
        const fErr = videoRefError(v.routes[rk].finderVideo)
        if (fErr) out.push(`${v.key} · ${ROUTE_LABELS[rk]}视频号 ID：${fErr}`)
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
        // theme 与 venues/popup 一起提交：后端是合并语义，全量提交才能保证
        // 「只改主题不会清门店」「只改门店不会把主题改回默认」
        theme: theme.value,
        // 空串也要发：后端是合并语义，传了才改（清空头图就是把字段写成 ''）
        homeHero: homeHero.value,
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
          heroImage: v.heroImage,
          images: v.images
            .filter((i) => i.src.trim())
            .map((i) => ({ src: i.src.trim(), caption: i.caption })),
          routes: Object.fromEntries(
            ROUTE_KEYS.map((rk) => [
              rk,
              {
                videoUrl: v.routes[rk].videoUrl,
                finderVideo: v.routes[rk].finderVideo,
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
        },
        blog: {
          enabled: blog.enabled,
          count: blog.count
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
      const resHome = (res as (MiniappConfig & { homeHero?: unknown }) | null)?.homeHero
      if (typeof resHome === 'string') homeHero.value = resHome
      if (res?.popup) Object.assign(popup, normalizePopup(res.popup))
      if (res?.blog) Object.assign(blog, normalizeBlog(res.blog))
      // 后端回什么就以什么为准（它可能驳回非法值）；没回就认为本次提交生效
      const resTheme = (res as (MiniappConfig & { theme?: unknown }) | null)?.theme
      savedTheme.value = typeof resTheme === 'string' ? normalizeTheme(resTheme) : theme.value
      theme.value = savedTheme.value
      // 保存后服务端已是新枚举，那句「经典已停用」就不用再挂着
      legacyThemeNotice.value = false
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

  /* ---------------- 页面头图 ---------------- */
  .hero-slot {
    padding: 10px 0;
    border-top: 1px dashed var(--el-border-color-lighter);
  }

  .hero-slot:first-of-type {
    border-top: 0;
    padding-top: 0;
  }

  .hero-slot__head {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    margin-bottom: 6px;
  }

  .hero-slot__title {
    font-size: 13px;
    font-weight: 500;
    color: var(--el-text-color-primary);
  }

  .hero-slot__meta {
    flex: 1;
    min-width: 0;
  }

  .hero-slot__meta code {
    padding: 0 3px;
    font-size: 11px;
    background: var(--el-fill-color-light);
    border-radius: 3px;
  }

  /* 头图预览按 16:9 展示（首页是铺满，横图更接近真实效果） */
  .thumb--wide {
    flex: 0 0 112px;
    width: 112px;
    height: 63px;
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

  /* ---------------- 主题卡片 ---------------- */
  .theme-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
  }

  .theme-card {
    position: relative;
    flex: 1 1 260px;
    max-width: 360px;
    padding: 12px;
    font: inherit;
    color: var(--el-text-color-primary);
    text-align: left;
    cursor: pointer;
    background: var(--el-fill-color-blank);
    border: 1px solid var(--el-border-color);
    border-radius: 10px;
    transition:
      border-color 0.15s,
      box-shadow 0.15s,
      background 0.15s;
  }

  .theme-card:hover {
    border-color: var(--el-color-primary-light-5);
  }

  .theme-card.is-active {
    background: var(--el-color-primary-light-9);
    border: 2px solid var(--el-color-primary);
    box-shadow: 0 2px 10px rgb(0 0 0 / 6%);
  }

  .theme-card.is-disabled {
    cursor: not-allowed;
    opacity: 0.72;
  }

  .theme-card__check {
    position: absolute;
    top: 8px;
    right: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    font-size: 11px;
    color: #fff;
    background: var(--el-color-primary);
    border-radius: 50%;
  }

  .theme-card__label {
    display: block;
    font-size: 14px;
    font-weight: 500;
  }

  .theme-card__desc {
    display: block;
    margin-top: 2px;
    font-size: 12px;
    line-height: 1.6;
    color: var(--el-text-color-secondary);
  }

  /* 手机示意框：120px 宽，纯 CSS 拼出来的「屏」 */
  .theme-phone {
    display: flex;
    flex-direction: column;
    gap: 6px;
    width: 120px;
    height: 184px;
    padding: 8px;
    margin-top: 10px;
    overflow: hidden;
    background: var(--el-bg-color-page);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 12px;
  }

  .ph-hero {
    display: block;
    flex: 0 0 auto;
    height: 44px;
    border-radius: 4px;
  }

  /* ===== 编辑：暖白底 + 沉浸大图 + 一条细线分区 + 整宽深色圆角按钮 ===== */
  .theme-phone.is-editorial {
    background: #fcfbf8;
    border-color: #ece7dc;
  }

  .ph-hero--warm {
    height: 62px;
    background: linear-gradient(135deg, #e7ddc9, #cbbfa6 55%, #a8997f);
    border-radius: 6px;
  }

  /* 一条细线把「图」和「正文」分开 */
  .is-editorial .ph-hair {
    display: block;
    flex: 0 0 auto;
    height: 1px;
    margin: 2px 0;
    background: #e7e2d8;
  }

  .is-editorial .ph-line {
    display: block;
    flex: 0 0 auto;
    height: 5px;
    background: #efeae0;
    border-radius: 3px;
  }

  .is-editorial .ph-line.is-short {
    width: 58%;
  }

  /* 整宽深色主按钮（圆角 = 无衬线现代感） */
  .is-editorial .ph-btn.is-wide {
    display: block;
    flex: 0 0 auto;
    width: 100%;
    height: 20px;
    margin-top: auto;
    background: #2b2724;
    border-radius: 6px;
  }

  /* ===== 静奢东方：米灰底 + 大图沉入米灰 + 衬线标题 + 发丝线 + 直角描边按钮 + 香槟金标签 ===== */
  .theme-phone.is-oriental {
    background: #f2f4f2;
    border-color: #e2e6e2;
  }

  /* 顶部大图向下渐隐进米灰底：这就是「沉入」的视觉做法 */
  .ph-hero--mist {
    position: relative;
    height: 64px;
    background: linear-gradient(180deg, #c9c3b4 0%, #d8d3c6 45%, #f2f4f2 100%);
    border-radius: 0;
  }

  /* 香槟金小字标签，直角细边 */
  .ph-gold {
    position: absolute;
    top: 4px;
    right: 4px;
    padding: 0 3px;
    font-size: 7px;
    line-height: 10px;
    color: #c19a4b;
    letter-spacing: 0.5px;
    border: 1px solid #c19a4b;
    border-radius: 0;
  }

  /* 衬线标题：真的用衬线字体渲染（Songti / STSong），和「无衬线」那张形成对比 */
  .ph-serif {
    display: block;
    flex: 0 0 auto;
    margin-top: 2px;
    font-family: 'Songti SC', 'STSong', 'Noto Serif SC', serif;
    font-size: 10px;
    line-height: 14px;
    color: #3a3a35;
    letter-spacing: 2px;
  }

  /* 发丝线：比编辑那张的细线更淡 */
  .is-oriental .ph-hairline {
    display: block;
    flex: 0 0 auto;
    height: 1px;
    margin: 2px 0;
    background: #ddd8cf;
  }

  .is-oriental .ph-line.is-flat {
    display: block;
    flex: 0 0 auto;
    height: 4px;
    background: #e3e0d8;
    border-radius: 0;
  }

  .is-oriental .ph-line.is-short {
    width: 52%;
  }

  /*
    1.5rpx 描边按钮：rpx 是小程序单位，1.5rpx ≈ 0.75px，
    网页预览里按 1px 画才能看见；直角 + 衬线字，是全页最能区分主题的元素。
  */
  .is-oriental .ph-btn.is-outline {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 20px;
    margin-top: auto;
    font-family: 'Songti SC', 'STSong', serif;
    font-size: 8px;
    line-height: 1;
    color: #3a3a35;
    letter-spacing: 2px;
    background: transparent;
    border: 1px solid #3a3a35;
    border-radius: 0;
  }
</style>
