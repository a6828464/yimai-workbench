<template>
  <div>
    <!-- 个人发圈人设（保存到账号，多设备共用） -->
    <ElCard shadow="never" class="mb-4">
      <template #header>
        <div class="mk-card-head">
          <span class="font-500">个人发圈人设</span>
          <div class="flex-c gap-3 flex-wrap">
            <span class="text-xs text-gray-400"
              >品牌固定「{{ BRAND_NAME }}」· 城市固定「{{ BRAND_CITY }}」</span
            >
            <ElButton
              size="small"
              type="primary"
              plain
              :loading="savingPersona"
              @click="savePersona"
              >保存人设</ElButton
            >
          </div>
        </div>
      </template>
      <div class="flex items-center flex-wrap gap-x-4 gap-y-1 mb-1 text-xs text-g-500">
        <span
          >姓名/性别/年龄取自个人中心：<b class="text-g-800">{{ my.name || '未设置' }}</b
          >{{ my.gender ? ` · ${my.gender}` : '' }}{{ my.age ? ` · ${my.age}岁` : '' }}</span
        >
        <ElButton link type="primary" size="small" @click="$router.push('/yimai/profile')"
          >去个人中心修改</ElButton
        >
      </div>
      <ElRow :gutter="12">
        <ElCol :xs="12" :sm="8" :lg="5">
          <ElFormItem :label-position="formLabelPosition" label="身份角色">
            <ElSelect v-model="persona.role">
              <ElOption v-for="r in PERSONA_ROLES" :key="r" :label="r" :value="r" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :sm="8" :lg="4">
          <ElFormItem :label-position="formLabelPosition" label="从业年限"
            ><ElInput v-model="persona.years" placeholder="如：5年"
          /></ElFormItem>
        </ElCol>
        <ElCol :xs="24" :lg="12">
          <ElFormItem :label-position="formLabelPosition" label="专业">
            <ElSelect
              v-model="persona.specialties"
              multiple
              filterable
              allow-create
              default-first-option
              collapse-tags
              :max-collapse-tags="6"
              class="w-full"
              placeholder="普拉提/瑜伽/康复理疗…可多选、可自定义"
            >
              <ElOption v-for="s in SPECIALTY_OPTIONS" :key="s" :label="s" :value="s" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :span="24">
          <ElFormItem :label-position="formLabelPosition" label="客户画像">
            <ElSelect
              v-model="persona.audiences"
              multiple
              filterable
              allow-create
              default-first-option
              collapse-tags
              :max-collapse-tags="6"
              class="w-full"
              placeholder="选择你的典型客户画像（可多选、可自定义添加）"
            >
              <ElOption v-for="a in AUDIENCE_OPTIONS" :key="a" :label="a" :value="a" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
      </ElRow>
    </ElCard>

    <!-- 本条设置 -->
    <ElCard shadow="never" class="mb-4">
      <template #header><span class="font-500">本条朋友圈设置</span></template>
      <ElRow :gutter="12">
        <ElCol :xs="24" :md="6">
          <ElFormItem :label-position="formLabelPosition" label="灵感主题">
            <ElSelect v-model="groupId">
              <ElOption v-for="g in inspirationGroups" :key="g.id" :label="g.label" :value="g.id" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="8">
          <ElFormItem :label-position="formLabelPosition" label="案例/选题类型">
            <!--
              「带写作方向」这句只在超管下成立（写作方向已随口径块收敛到超管）；
              非超管若还写着会指向一个看不到的东西，故按角色给两种文案（可用性不变）。
            -->
            <ElSelect
              v-model="inspirationId"
              filterable
              :placeholder="isSuper ? '选择案例/选题（带写作方向）' : '选择案例/选题'"
            >
              <ElOption
                v-for="it in inspirationItems"
                :key="it.id"
                :label="it.label"
                :value="it.id"
              />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="5">
          <ElFormItem :label-position="formLabelPosition" label="语气">
            <ElSelect v-model="tone">
              <ElOption v-for="v in MOMENT_TONES" :key="v" :label="v" :value="v" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="5">
          <ElFormItem :label-position="formLabelPosition" label="目标（含销售强度）">
            <ElSelect v-model="goalId">
              <ElOption v-for="g in goalOptions" :key="g.id" :label="g.label" :value="g.id" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="5">
          <ElFormItem :label-position="formLabelPosition" label="表达方式">
            <ElSelect v-model="expression">
              <ElOption v-for="v in MOMENT_EXPRESSIONS" :key="v" :label="v" :value="v" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="6">
          <ElFormItem :label-position="formLabelPosition" label="内容长度（单条）">
            <ElSelect v-model="lengthId">
              <!-- 非超管看不到档位里的字数区间（展示层去数字，常量仍喂提示词） -->
              <ElOption
                v-for="l in lengthOptions"
                :key="l.id"
                :label="momentsLengthLabel(l.label, isSuper)"
                :value="l.id"
              />
              <ElOption :label="MOMENT_LENGTH_CUSTOM" :value="MOMENT_LENGTH_CUSTOM" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="5">
          <ElFormItem :label-position="formLabelPosition" label="生成条数">
            <ElRadioGroup v-model="draftCount">
              <ElRadioButton v-for="n in draftCountOptions" :key="n" :value="n">
                {{ n }} 条
              </ElRadioButton>
            </ElRadioGroup>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="4">
          <ElFormItem :label-position="formLabelPosition" label="多分行">
            <ElSwitch v-model="multiLine" />
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="5">
          <ElFormItem :label-position="formLabelPosition" label="表情符号">
            <ElSelect v-model="emojiModeId">
              <!-- 非超管看不到「每 N 字 1 个」的检验口径 -->
              <ElOption
                v-for="m in emojiModes"
                :key="m.id"
                :label="emojiUsageLabel(m.label, isSuper)"
                :value="m.id"
              />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="6">
          <ElFormItem :label-position="formLabelPosition" label="生成期防折叠">
            <ElSwitch v-model="antiFoldEnabled" />
          </ElFormItem>
        </ElCol>
      </ElRow>

      <!--
        口径说明：让「这一档到底约束了什么」在界面上可辨，而不是靠猜。
        v-if="isSuper"：字数/表情/目标口径与写作方向属**提示词内部口径**，普通用户前台不渲染
        （用 v-if 而非 disabled —— 不需要用户看见一个点不动的灰块）。
      -->
      <div v-if="isSuper" class="mk-hint-block">
        <div v-if="lengthHint"><b>字数口径：</b>{{ lengthHint }}</div>
        <div v-if="emojiHint"><b>表情口径：</b>{{ emojiHint }}</div>
        <div v-if="goalHint"><b>目标口径：</b>{{ goalHint }}</div>
        <div v-if="inspirationHint"><b>写作方向：</b>{{ inspirationHint }}</div>
      </div>

      <!-- 立场铁律 / 优先级链 / 去 AI 味：来自 api 层常量，只读展示（仅超管可见，同口径说明） -->
      <ElCollapse v-if="isSuper && ruleBlocks.length" class="mk-rule-collapse">
        <ElCollapseItem
          :title="`生成铁律（${ruleBlocks.length} 条，来自 api 层常量）`"
          name="rules"
        >
          <div v-for="r in ruleBlocks" :key="r.label" class="mk-rule-item">
            <div class="mk-rule-item__label">{{ r.label }}</div>
            <div class="mk-rule-item__body">{{ r.text }}</div>
          </div>
        </ElCollapseItem>
      </ElCollapse>

      <ElRow v-if="isCustomLength" :gutter="12">
        <ElCol :xs="12" :md="6">
          <ElFormItem :label-position="formLabelPosition" label="最少字数">
            <ElInputNumber
              v-model="customLengthMin"
              :min="10"
              :max="490"
              :step="10"
              controls-position="right"
              class="!w-full"
            />
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="6">
          <ElFormItem :label-position="formLabelPosition" label="最多字数">
            <ElInputNumber
              v-model="customLengthMax"
              :min="20"
              :max="500"
              :step="10"
              controls-position="right"
              class="!w-full"
            />
          </ElFormItem>
        </ElCol>
      </ElRow>

      <!--
        图片素材：最多 3 张 + 每张一句说明。
        图片**真的会被大模型看到**：选图即在本机压缩成 dataURL 并随本次生成下发，
        所以下面这句文案必须如实写清「会发送给模型服务商 / 不落本系统」（旧文案的
        「只在本机预览，不会上传服务器」在真读图实现后就是假话，已一并改掉）。
      -->
      <div class="mk-sub-title">图片素材（最多 {{ maxImages }} 张）</div>
      <div class="text-xs text-g-500 leading-5 mb-2">
        图片会随本次生成发送给「模型配置」里所选的大模型服务商用于识别，<b>不会</b>保存到本系统；请勿上传含会员隐私（人脸/姓名/手机号/病历）的照片。
      </div>
      <div class="mk-image-row">
        <ElUpload
          v-model:file-list="uploadFileList"
          :auto-upload="false"
          list-type="picture-card"
          accept="image/*"
          :limit="maxImages"
          :on-exceed="onImageExceed"
          :on-change="onImageChange"
          :on-remove="onImageRemove"
        >
          <div class="mk-image-add">
            <i class="ri-add-line" />
            <span class="text-xs">加图</span>
          </div>
        </ElUpload>
      </div>
      <div v-if="compressingCount" class="text-xs text-g-500 leading-5 mt-2">
        <i class="ri-loader-4-line" /> 正在压缩 {{ compressingCount }} 张图片…
      </div>
      <div v-if="uploadFileList.length" class="mk-image-notes">
        <div v-for="(img, i) in uploadFileList" :key="img.uid" class="mk-image-note">
          <span class="mk-image-note__idx">图{{ i + 1 }}</span>
          <ElInput
            v-model="imageNotes[img.uid ?? -1]"
            placeholder="这张图是什么（如：学员体态对比照）"
          />
        </div>
      </div>

      <ElFormItem :label-position="formLabelPosition" label="我想说" class="mt-2">
        <ElInput
          v-model="userInput"
          type="textarea"
          :rows="3"
          placeholder="我想说什么 / 我想表达什么（越具体，生成越贴近）"
        />
      </ElFormItem>
      <div class="mk-btn-row">
        <ElButton type="primary" :loading="generating" @click="generate()">生成朋友圈</ElButton>
        <ElButton @click="openHistory">历史生成记录</ElButton>
        <ElButton @click="favVisible = true">朋友圈库（{{ favorites.length }}）</ElButton>
      </div>
    </ElCard>

    <!-- 生成结果 -->
    <ElCard v-if="hasResult || warning || generating" shadow="never" class="mb-4">
      <template #header>
        <div class="mk-card-head">
          <span class="font-500">生成结果（{{ drafts.length }} 条）</span>
          <div class="flex-c gap-3 flex-wrap">
            <ElButton v-if="isSuper && report" link size="small" @click="reportVisible = true">
              质检报告{{ report.passed ? '' : `（${failedCount} 项待改）` }}
            </ElButton>
            <ElButton
              v-if="isSuper && promptMessages.length"
              link
              size="small"
              @click="promptVisible = true"
            >
              查看本次提示词
            </ElButton>
            <ElTag size="small" :type="source === 'llm' ? 'success' : 'warning'">{{
              source === 'llm' ? '大模型生成' : '本地模板草稿'
            }}</ElTag>
          </div>
        </div>
      </template>
      <!-- 读图能力提醒：内容完全来自引擎返回值（视图不重算模型能力判定） -->
      <ElAlert
        v-if="visionHint"
        :title="visionHint"
        type="warning"
        show-icon
        :closable="false"
        class="mb-3"
      />
      <ElAlert
        v-if="warning"
        :title="warning"
        type="warning"
        show-icon
        :closable="false"
        class="mb-3"
      />
      <div v-loading="generating">
        <div v-if="drafts.length" class="mk-result-list">
          <div v-for="(d, i) in drafts" :key="d.key" class="mk-result-card">
            <div class="mk-result-card__head">
              <span class="mk-result-card__title">{{ d.label }}</span>
              <!--
                三态：本卡有质检项才谈「通过 / 不通过」；一项都没匹配到就如实说没有，
                不要用「质检通过」冒充（那是把「没数据」说成「没问题」）。
                质检徽标属「系统生成口径」的派生面（失败项名与阈值同源），非超管整块不渲染。
              -->
              <template v-if="isSuper">
                <template v-if="draftChecks(i).length">
                  <span v-if="draftFailedCount(i)" class="mk-result-card__warn"
                    >{{ draftFailedCount(i) }} 项不匹配</span
                  >
                  <span v-else class="mk-result-card__ok">质检通过</span>
                </template>
                <span v-else class="mk-result-card__none">
                  无质检数据（未匹配到{{ draftKey(i) }}）
                </span>
              </template>
            </div>

            <!-- 朋友圈样式预览 -->
            <div class="mk-moment-preview">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg overflow-hidden shrink-0 bg-theme/10 flex-c">
                  <img
                    v-if="my.avatar"
                    :src="my.avatar"
                    class="w-full h-full object-cover"
                    alt="avatar"
                  />
                  <i v-else class="ri-user-smile-line text-xl text-theme" />
                </div>
                <div>
                  <div class="text-sm font-600 text-g-900">{{
                    my.name || persona.role || '我'
                  }}</div>
                  <div class="text-xs text-g-500 mt-0.5">刚刚 · 来自一麦</div>
                </div>
              </div>
              <p class="mk-moment-preview__body">{{ d.content }}</p>
              <div class="mt-3 flex items-center gap-4 text-xs text-g-500">
                <span class="flex-c gap-1"><i class="ri-heart-line" /> 赞</span>
                <span class="flex-c gap-1"><i class="ri-chat-1-line" /> 评论</span>
              </div>
            </div>

            <!-- 单选一张卡：这条的首评跟着这条走（不串位） -->
            <div v-if="d.reply" class="mk-reply-card">
              <div class="flex-cb mb-1">
                <span class="text-xs font-500 text-g-600"
                  ><i class="ri-chat-3-line mr-1" />评论区首条回复建议</span
                >
                <ElButton link type="primary" size="small" @click="copyText(d.reply)"
                  >复制回复</ElButton
                >
              </div>
              <p class="text-sm leading-6 text-g-700 whitespace-pre-wrap">{{ d.reply }}</p>
            </div>

            <!-- 质检明细：数据来自 api 层质检函数，视图只渲染；外层只按本卡 checks 判定。
                 明细里逐条印着阈值口径（如「单条字数 50-120字」），故整块仅超管可见。 -->
            <div v-if="isSuper && draftChecks(i).length" class="mk-check-list">
              <div
                v-for="c in draftChecks(i)"
                :key="c.label"
                class="mk-check-item"
                :class="{ 'is-fail': !c.passed }"
              >
                <span class="mk-check-item__label">{{ c.label }}</span>
                <span class="mk-check-item__detail">{{ c.detail || c.status }}</span>
              </div>
            </div>

            <div class="mk-btn-row mt-3">
              <ElButton type="primary" plain @click="copyDraft(d)">复制文案</ElButton>
              <ElButton plain @click="saveOneFavorite(d)">收藏这条</ElButton>
              <ElButton text @click="openDraftEditor(i)">载入编辑</ElButton>
            </div>
          </div>
        </div>
        <div v-else-if="!generating" class="mk-result-empty">
          还没有结果。选择主题与目标后点「生成朋友圈」。
        </div>
        <div class="mk-btn-row mt-3">
          <ElButton v-if="drafts.length" @click="copyAll">复制全部</ElButton>
          <ElButton v-if="drafts.length" text @click="generate()">重新生成</ElButton>
        </div>
      </div>
    </ElCard>

    <!-- 质检报告（api 层 auditMomentsResult：提示词层 12 项 + 成稿层每条的 6 项）
         抽屉内逐条列着阈值口径，与已 gated 的「字数/表情/目标口径」块逐字等价，
         因此整块仅超管渲染（非超管不渲染，而非 disabled）。 -->
    <ElDrawer
      v-if="isSuper"
      v-model="reportVisible"
      title="质检报告 · 提示词与成稿匹配度"
      size="520px"
    >
      <template v-if="report">
        <ElAlert
          :title="`${report.statusText}${report.summary ? ` · ${report.summary}` : ''}`"
          :type="report.passed ? 'success' : 'warning'"
          show-icon
          :closable="false"
          class="mb-3"
        />
        <div class="mk-sub-title">提示词层（前端选择是否真的进了提示词）</div>
        <div
          v-for="c in promptChecks"
          :key="`p-${c.label}`"
          class="mk-check-item"
          :class="{ 'is-fail': !c.passed }"
        >
          <span class="mk-check-item__label">{{ c.label }}</span>
          <span class="mk-check-item__detail">{{ c.detail || c.status }}</span>
        </div>
        <div class="mk-sub-title mt-3">成稿层（每条单独校验）</div>
        <div v-if="cardListMode" class="m-card-list">
          <MobileCard
            v-for="cd in draftCheckGroups"
            :key="cd.label"
            :title="cd.label"
            :subtitle="`${cd.checks.filter((c) => c.passed).length}/${cd.checks.length} 项通过`"
            :tags="[
              {
                text: cd.checks.every((c) => c.passed) ? '全部通过' : '存在不匹配',
                type: cd.checks.every((c) => c.passed) ? 'success' : 'danger'
              }
            ]"
          >
            <div
              v-for="c in cd.checks"
              :key="c.label"
              class="mk-check-item"
              :class="{ 'is-fail': !c.passed }"
            >
              <span class="mk-check-item__label">{{ c.label }}</span>
              <span class="mk-check-item__detail">{{ c.detail || c.status }}</span>
            </div>
          </MobileCard>
        </div>
        <div v-else>
          <div v-for="cd in draftCheckGroups" :key="cd.label" class="mb-3">
            <div class="text-xs font-500 text-g-700 mb-1">{{ cd.label }}</div>
            <div
              v-for="c in cd.checks"
              :key="c.label"
              class="mk-check-item"
              :class="{ 'is-fail': !c.passed }"
            >
              <span class="mk-check-item__label">{{ c.label }}</span>
              <span class="mk-check-item__detail">{{ c.detail || c.status }}</span>
            </div>
          </div>
        </div>
        <div v-if="report.issues.length" class="mk-sub-title mt-3">问题清单</div>
        <ul v-if="report.issues.length" class="mk-issue-list">
          <li v-for="(s, i) in report.issues" :key="i">{{ s }}</li>
        </ul>
      </template>
      <div v-else class="mk-result-empty">本次生成没有质检数据。</div>
    </ElDrawer>

    <!-- 查看本次提示词：内容取自 api 层返回的 prompt，视图不另抄一份（仅超管可见） -->
    <ElDrawer v-if="isSuper" v-model="promptVisible" title="本次下发的提示词" size="520px">
      <ElAlert
        title="这是本次真正提交给模型的提示词原文（只读）。"
        type="info"
        show-icon
        :closable="false"
        class="mb-3"
      />
      <div v-for="(m, i) in promptMessages" :key="i" class="mk-prompt-block">
        <div class="mk-prompt-block__role">{{ m.role }}</div>
        <pre class="mk-prompt-block__body">{{ m.content }}</pre>
        <ElButton link type="primary" size="small" @click="copyText(m.content)">复制这段</ElButton>
      </div>
    </ElDrawer>

    <!-- 单条载入编辑 -->
    <ElDialog
      v-model="draftEditVisible"
      title="编辑这一条"
      :width="isHandheld ? '92%' : '620px'"
      align-center
    >
      <div class="text-xs text-gray-400 mb-1">正文</div>
      <ElInput v-model="draftEdit.content" type="textarea" :rows="8" resize="none" class="mb-3" />
      <div class="text-xs text-gray-400 mb-1">首条回复（发布后自己评论）</div>
      <ElInput v-model="draftEdit.reply" type="textarea" :rows="3" resize="none" />
      <template #footer>
        <div class="mk-btn-row">
          <ElButton @click="draftEditVisible = false">取消</ElButton>
          <ElButton type="primary" @click="applyDraftEdit">保存并重算质检</ElButton>
        </div>
      </template>
    </ElDialog>

    <!-- 朋友圈库（收藏） -->
    <ElDrawer v-model="favVisible" title="朋友圈库" size="440px" @open="onFavoritesOpen">
      <!-- 来源标注：读不到云端时明确说「显示本机缓存 / 本机无缓存」，绝不冒充「暂无收藏」 -->
      <ElAlert
        v-if="favOrigin !== 'ok'"
        :title="favOfflineText"
        type="warning"
        show-icon
        :closable="false"
        class="mb-3"
      />
      <ElAlert
        title="收藏内容会保存到门店服务端并可跨设备同步。请勿写入会员真实姓名、手机号、身体状况等隐私信息；含会员信息的案例文案建议先匿名化（如「一位 32 岁宝妈」而不是真实姓名）。"
        type="info"
        show-icon
        :closable="false"
        class="mb-3"
      />
      <div v-loading="favLoading">
        <!-- 手持端：一条收藏一张卡（复用项目统一降级范式 MobileCard） -->
        <div v-if="cardListMode" class="m-card-list">
          <template v-if="favorites.length">
            <MobileCard
              v-for="f in favorites"
              :key="f.id"
              :title="f.title || '未命名文案'"
              :subtitle="f.createdAt"
              :actions="[
                { text: '载入编辑', type: 'primary', onClick: () => loadFavorite(f) },
                { text: '复制正文', onClick: () => copyText(f.content) },
                { text: '复制首评', show: !!f.reply, onClick: () => copyText(f.reply || '') },
                { text: '删除', type: 'danger', onClick: () => onRemoveFavorite(f.id) }
              ]"
            >
              <p class="mk-card-text">{{ f.content }}</p>
              <!-- 首评独立呈现（缺失时不显示空壳） -->
              <div v-if="f.reply" class="mk-fav-reply">💬 {{ f.reply }}</div>
            </MobileCard>
          </template>
          <div v-else class="m-card-list__empty">收藏的文案会出现在这里，可反复复用</div>
        </div>

        <!-- 桌面端：保持原有紧凑列表，逐值不变 -->
        <div v-else-if="favorites.length">
          <div
            v-for="f in favorites"
            :key="f.id"
            class="mb-3 p-3 rounded-lg border border-gray-100 dark:border-gray-700"
          >
            <div class="flex-cb mb-1">
              <span class="text-xs text-gray-400">{{ f.createdAt }} · {{ f.title }}</span>
              <ElIcon class="cursor-pointer" color="#f56c6c" @click="onRemoveFavorite(f.id)"
                ><Delete
              /></ElIcon>
            </div>
            <div class="text-sm leading-5 whitespace-pre-wrap line-clamp-4">{{ f.content }}</div>
            <div v-if="f.reply" class="mk-fav-reply">💬 {{ f.reply }}</div>
            <div class="mt-2 flex gap-2">
              <ElButton link type="primary" size="small" @click="loadFavorite(f)"
                >载入编辑</ElButton
              >
              <ElButton link size="small" @click="copyText(f.content)">复制正文</ElButton>
              <ElButton v-if="f.reply" link size="small" @click="copyText(f.reply || '')"
                >复制首评</ElButton
              >
            </div>
          </div>
        </div>
        <ElEmpty v-else-if="!cardListMode" description="收藏的文案会出现在这里，可反复复用" />
      </div>
    </ElDrawer>

    <!-- 历史生成记录（按账号云端保存，最近300条） -->
    <ElDrawer v-model="historyVisible" title="历史生成朋友圈" size="460px">
      <div v-loading="historyLoading">
        <!-- 手持端：一条记录一张卡 -->
        <div v-if="cardListMode" class="m-card-list">
          <template v-if="history.length">
            <MobileCard
              v-for="h in history"
              :key="h.id"
              :title="h.title || '未命名文案'"
              :subtitle="h.createdAt"
              :note="h.reply ? `💬 ${h.reply}` : ''"
              note-label="首评"
              :actions="[
                { text: '载入编辑', type: 'primary', onClick: () => loadHistory(h) },
                { text: '复制文案', onClick: () => copyText(h.content) },
                { text: '复制首评', show: !!h.reply, onClick: () => copyText(h.reply) },
                { text: '删除', type: 'danger', onClick: () => removeHistory(h.id) }
              ]"
            >
              <p class="mk-card-text">{{ h.content }}</p>
            </MobileCard>
          </template>
          <div v-else-if="!historyLoading" class="m-card-list__empty">
            每次生成的文案都会自动记录在这里
          </div>
        </div>

        <div v-else-if="history.length">
          <div
            v-for="h in history"
            :key="h.id"
            class="mb-3 p-3 rounded-lg border border-gray-100 dark:border-gray-700"
          >
            <div class="flex-cb mb-1">
              <span class="text-xs text-gray-400">{{ h.createdAt }} · {{ h.title }}</span>
              <ElIcon
                class="cursor-pointer shrink-0 ml-2"
                color="#f56c6c"
                @click="removeHistory(h.id)"
                ><Delete
              /></ElIcon>
            </div>
            <div class="text-sm leading-5 whitespace-pre-wrap line-clamp-4">{{ h.content }}</div>
            <div v-if="h.reply" class="mt-1.5 text-xs text-g-500 leading-5 line-clamp-2"
              >💬 {{ h.reply }}</div
            >
            <div class="mt-2 flex gap-2">
              <ElButton link type="primary" size="small" @click="loadHistory(h)">载入编辑</ElButton>
              <ElButton link size="small" @click="copyText(h.content)">复制文案</ElButton>
              <ElButton v-if="h.reply" link size="small" @click="copyText(h.reply)"
                >复制首评</ElButton
              >
            </div>
          </div>
        </div>
        <ElEmpty v-else-if="!historyLoading" description="每次生成的文案都会自动记录在这里" />
      </div>
    </ElDrawer>

    <!-- 朋友圈防折叠（同一条文案换号发布时避免被折叠成相似内容） -->
    <AntiFoldTool :latest-result="latestResultContent" />
  </div>
</template>

<script setup lang="ts">
  import AntiFoldTool from './anti-fold-tool.vue'
  import { useMarketingFavorites } from './marketing-favorites'
  import {
    AUDIENCE_OPTIONS,
    BRAND_CITY,
    BRAND_NAME,
    DEFAULT_EMOJI_USAGE_ID,
    EMOJI_USAGE_MODES,
    MOMENT_EXPRESSIONS,
    MOMENT_GOALS_V2,
    MOMENT_LENGTHS,
    MOMENT_LENGTH_CUSTOM,
    MOMENT_TONES,
    MOMENTS_ANTI_FOLD_RULES,
    MOMENTS_CATALOG,
    MOMENTS_DE_AI_RULE,
    MOMENTS_MAX_IMAGES,
    MOMENTS_PRIORITY_CHAIN,
    MOMENTS_STANCE_RULE,
    PERSONA_ROLES,
    SPECIALTY_OPTIONS,
    auditMomentsResult,
    composeImageSummary,
    compressMomentImage,
    findEmojiUsageMode,
    findMomentGoal,
    findMomentLength,
    findMomentsInspiration,
    generateMomentsCopy,
    emojiUsageLabel,
    momentsLengthLabel,
    toAuditDrafts,
    toDraftRows
  } from './marketing-api-bridge'
  import type {
    DraftCheck,
    MktDraftRow,
    MomentsInput,
    MomentsMatchReport,
    MomentsCategoryItem,
    PromptCheck,
    PromptLayer
  } from './marketing-api-bridge'
  import type { MarketingHistoryItem, MyProfile } from '@/api/my'
  import {
    defaultMyProfile,
    fetchMyProfile,
    saveMyProfile,
    fetchMarketingHistory,
    removeMarketingHistory
  } from '@/api/my'
  import { useAiConfigStore } from '@/store/modules/ai-config'
  import { useUserStore } from '@/store/modules/user'
  import { useDevice } from '@/hooks/core/useDevice'
  import { Delete } from '@element-plus/icons-vue'
  import { ElMessage, ElTag } from 'element-plus'
  import type { UploadUserFile } from 'element-plus'

  defineOptions({ name: 'MomentsTool' })

  const { isHandheld } = useDevice()

  /**
   * 超管判定：与全仓既有范式一致（`marketing/index.vue:62`、`views/yimai/tasks/index.vue` 等）。
   *
   * 只读 `userStore.getUserInfo.roles`（登录态来自服务端下发的用户信息），
   * **不接受** URL query / localStorage 等外部可写来源覆盖 —— 否则任何人加个
   * `?super=1` 就能把提示词原文与内部口径看出来，等于没有控制。
   * 非超管直接**不渲染**（而不是 disabled）：这些内容本就不属于前台用户。
   */
  const userStore = useUserStore()
  const isSuper = computed(() => (userStore.getUserInfo.roles ?? []).includes('R_SUPER'))

  /**
   * 表单 label 位置：手持端（≤768，与 handheld-only 同一档）改到控件上方。
   *
   * 本文件所有 ElFormItem 都没有外层 ElForm（全文件 0 个 ElForm），
   * 所以 label-position 只能写在 form-item 自己身上，无法靠 form 继承。
   * label 在左时「身份角色」「从业年限」「表达方式」等控件的可用宽度只剩 48px 量级，
   * 改成 top 后控件拿回整行宽度；桌面端保持原样（undefined = 不覆盖 EP 默认 right）。
   * 断点与 index.vue 的 handheld-only 保持一致，避免「样式已降级、label 还没降级」的错位。
   */
  const formLabelPosition = computed<'top' | undefined>(() =>
    isHandheld.value ? 'top' : undefined
  )

  /**
   * 「朋友圈库 / 历史生成记录」这类**一条记录一张卡**的列表，手持端改用 MobileCard。
   * 表单区不用卡片：那里是输入控件，塞进卡片只会把可用宽度再压窄。
   */
  const cardListMode = computed(() => isHandheld.value)

  const aiStore = useAiConfigStore()
  const favorites = computed(() =>
    aiStore.marketing.favorites.filter((f) => f.platform === '朋友圈')
  )

  /** 个人中心资料（姓名/性别/年龄等自动带入人设） */
  const my = ref<MyProfile>(defaultMyProfile())

  /** 人设编辑区（保存在账号 profile 上） */
  const persona = ref({
    role: '全职老师',
    years: '',
    specialties: [] as string[],
    audiences: [] as string[]
  })

  onMounted(async () => {
    my.value = await fetchMyProfile()
    persona.value = {
      role: my.value.persona.role,
      years: my.value.years,
      specialties: [...my.value.specialties],
      audiences: [...my.value.persona.audiences]
    }
  })

  const savingPersona = ref(false)
  async function savePersona() {
    savingPersona.value = true
    try {
      await saveMyProfile({
        years: persona.value.years,
        specialties: persona.value.specialties,
        persona: { role: persona.value.role, audiences: persona.value.audiences }
      })
      my.value = await fetchMyProfile(true)
      ElMessage.success('人设已保存到账号，换设备登录也不会丢')
    } catch (e) {
      ElMessage.error(`保存失败：${String(e).slice(0, 60)}`)
    } finally {
      savingPersona.value = false
    }
  }

  // ==================== 本条设置：选项口径全部直接引用 api 层常量 ====================

  /** 选题目录（api 层单一事实来源：组 id / 项 id / promptHint） */
  const inspirationGroups = MOMENTS_CATALOG
  const groupId = ref(MOMENTS_CATALOG[0].id)
  const inspirationId = ref(MOMENTS_CATALOG[0].items[0].id)

  const currentGroup = computed(
    () => inspirationGroups.find((g) => g.id === groupId.value) ?? inspirationGroups[0]
  )
  const inspirationItems = computed<MomentsCategoryItem[]>(() => currentGroup.value.items)
  /** 选中选题（api 查表：未命中回落「客户案例」首项，返回带 groupId/groupLabel 的完整项） */
  const currentInspiration = computed(() => findMomentsInspiration(inspirationId.value))
  const inspirationHint = computed(() => currentInspiration.value.promptHint)
  watch(groupId, () => {
    if (!currentGroup.value.items.some((it) => it.id === inspirationId.value)) {
      inspirationId.value = currentGroup.value.items[0].id
    }
  })

  const tone = ref(MOMENT_TONES[0])
  const expression = ref(MOMENT_EXPRESSIONS[0])

  /** 长度档（api 层 MOMENT_LENGTHS：id 绑定 + label 展示；自定义档见 MOMENT_LENGTH_CUSTOM） */
  const lengthOptions = MOMENT_LENGTHS
  const isCustomLength = computed(() => lengthId.value === MOMENT_LENGTH_CUSTOM)
  const lengthId = ref<string>(MOMENT_LENGTHS[1].id)
  /** api 查表解析长度档：id → label → 数值范围（兼容历史 label），未命中回落标准版 */
  const currentLength = computed(() => findMomentLength(lengthId.value))
  const customLengthMin = ref(60)
  const customLengthMax = ref(100)
  const lengthHint = computed(() => {
    if (isCustomLength.value) {
      return `自定义 ${customLengthMin.value}-${customLengthMax.value} 字，按**每条**独立计算，不是多条合计。`
    }
    const l = currentLength.value
    return `「${l.label}」按**每条**独立计算（${l.min}-${l.max} 字），不是多条合计。`
  })

  const multiLine = ref(true)

  /** emoji 四档（api 层 EMOJI_USAGE_MODES，含 charsPerSymbol；解析走 findEmojiUsageMode） */
  const emojiModes = EMOJI_USAGE_MODES
  const emojiModeId = ref<string>(DEFAULT_EMOJI_USAGE_ID)
  const currentEmoji = computed(() => findEmojiUsageMode(emojiModeId.value))
  const emojiHint = computed(() => {
    const m = currentEmoji.value
    return m.charsPerSymbol
      ? `${m.label} · 平均每 ${m.charsPerSymbol} 字 1 个 · ${m.desc}`
      : `${m.label} · ${m.desc}`
  })

  /** 目标（含销售强度三件套）：api 层 MOMENT_GOALS_V2 + findMomentGoal */
  const goalOptions = MOMENT_GOALS_V2
  const goalId = ref<string>(MOMENT_GOALS_V2[0].id)
  const currentGoal = computed(() => findMomentGoal(goalId.value))
  const goalHint = computed(() => {
    const g = currentGoal.value
    return `销售强度 ${g.salesLevelId} · 转化动作 ${g.conversionActionId} · 结尾行动指令 ${
      g.actionInstruction || '无（不加行动指令）'
    } · ${g.promptHint}`
  })

  /** 生成条数：1 条或 3 条（3 条时 api 层按「真实日常 / 专业信任 / 转化表达」差异化输出） */
  const draftCountOptions = [1, 3]
  const draftCount = ref(3)

  /** 生成期防折叠（api 层 MomentsInput.antiFoldEnabled） */
  const antiFoldEnabled = ref(false)

  // ==================== 图片素材（≤3 张 + 每张一句说明 + 真读图载荷） ====================

  const maxImages = MOMENTS_MAX_IMAGES
  /**
   * 图片素材：选图即在本机压缩成 JPEG dataURL（`compressMomentImage`，定义见 api-bridge），
   * 生成时随 `MomentsInput.imageUrls` 一起下发，模型才真的看得到图。
   *
   * 列表本体仍交给 ElUpload 维护（`v-model:file-list`）；另有两份视图状态：
   * - `imageUrlsByUid`：uid → dataURL（压缩成功后写入；失败/超限的图会在下方被移出列表，不留空洞）
   * - `imageNotes`：uid → 每张图的一句说明（进提示词的 `imageSummary`）
   * 张数与说明的对应关系都按 uid 关联，避免「删中间一张后说明整体错位」。
   */
  const uploadFileList = ref<UploadUserFile[]>([])
  const imageNotes = ref<Record<number, string>>({})
  /** 压缩产物：uid → dataURL（仅压缩成功的图有键） */
  const imageUrlsByUid = ref<Record<number, string>>({})
  /** 正在压缩的张数：>0 时禁止生成（否则会把「还没压完的图」静默漏掉） */
  const compressingCount = ref(0)

  function onImageExceed() {
    ElMessage.warning(
      `一条朋友圈最多配 ${maxImages} 张图（上限来自 api 层 MOMENTS_MAX_IMAGES），已忽略多余的图片`
    )
  }

  /** 把某张图移出列表（压缩失败 / 超限时调用），顺手回收 ElUpload 建的 blob: 预览 URL */
  function dropUploadFile(uid: number) {
    const target = uploadFileList.value.find((f) => f.uid === uid)
    if (target?.url?.startsWith('blob:')) URL.revokeObjectURL(target.url)
    uploadFileList.value = uploadFileList.value.filter((f) => f.uid !== uid)
    const nextNotes = { ...imageNotes.value }
    delete nextNotes[uid]
    imageNotes.value = nextNotes
    const nextUrls = { ...imageUrlsByUid.value }
    delete nextUrls[uid]
    imageUrlsByUid.value = nextUrls
  }

  /**
   * 选图回调：ElUpload 已经把文件放进列表（`auto-upload=false`，此时 status=ready），
   * 这里立刻本地读图 → 压缩 → 存 dataURL；任何失败路径都**把该图移出列表**并如实提示，
   * 绝不留下「列表里看得见、但模型看不到」的静默漏图。
   */
  async function onImageChange(file: UploadUserFile) {
    const uid = file.uid ?? -1
    const raw = file.raw
    if (uid < 0 || !raw) return
    compressingCount.value += 1
    try {
      const res = await compressMomentImage(raw)
      if (!res.ok) {
        dropUploadFile(uid)
        ElMessage.warning(res.message)
        return
      }
      imageUrlsByUid.value = { ...imageUrlsByUid.value, [uid]: res.dataUrl }
    } finally {
      compressingCount.value -= 1
    }
  }

  /** 移除回调：把该张的 dataURL / 说明一起删掉（删的是哪张就删哪张，不按序位移） */
  function onImageRemove(file: UploadUserFile) {
    dropUploadFile(file.uid ?? -1)
  }

  /**
   * 本次随生成下发的图片载荷：**按 `uploadFileList` 的顺序**取 dataURL，
   * 与界面上的「图1/图2/图3」编号严格同序（顺序即用户看到的顺序）。
   * 用 computed 而不是手动维护的数组，是为了不可能出现「列表删过一张后载荷错位」。
   */
  const imageUrls = computed(() =>
    uploadFileList.value
      .map((f) => imageUrlsByUid.value[f.uid ?? -1] ?? '')
      .filter((u) => !!u)
      .slice(0, maxImages)
  )

  const imageSummary = computed(() =>
    composeImageSummary(
      uploadFileList.value.map((f) => ({
        name: f.name ?? '',
        note: imageNotes.value[f.uid ?? -1] ?? ''
      }))
    )
  )

  // ==================== 生成铁律（api 层常量，只读展示） ====================

  /**
   * 立场铁律 / 优先级链 / 去 AI 味三条常量由 api 层导出（规格 §1.1 / §1.2 / §1.8）。
   * 这里只**展示**原文，视图不复制文本、不重新组装规则，避免与提示词口径漂移。
   */
  const ruleBlocks = computed(() => {
    const blocks = [
      { label: '立场铁律', text: MOMENTS_STANCE_RULE },
      { label: '优先级链', text: MOMENTS_PRIORITY_CHAIN },
      { label: '去 AI 味', text: MOMENTS_DE_AI_RULE }
    ]
    // 生成期防折叠开关打开时，把 api 层那 5 条规则原文一并展示（仍是只读引用）
    if (antiFoldEnabled.value) {
      blocks.push({ label: '生成期防折叠', text: MOMENTS_ANTI_FOLD_RULES.join('\n') })
    }
    return blocks
  })

  // ==================== 生成 ====================

  const userInput = ref('')
  const generating = ref(false)
  const drafts = ref<MktDraftRow[]>([])
  const source = ref<'llm' | 'fallback'>('fallback')
  const warning = ref('')
  const report = ref<MomentsMatchReport | null>(null)
  const promptMessages = ref<PromptLayer[]>([])
  /** 引擎返回的「当前模型可能不读图」如实提醒（有图才可能非空，视图不重算） */
  const visionHint = ref('')
  const favVisible = ref(false)
  const reportVisible = ref(false)
  const promptVisible = ref(false)

  const historyVisible = ref(false)
  const historyLoading = ref(false)
  const history = ref<MarketingHistoryItem[]>([])

  const hasResult = computed(() => drafts.value.length > 0)
  const latestResultContent = computed(() => drafts.value[0]?.content ?? '')
  const promptText = computed(() =>
    promptMessages.value.map((m) => `[${m.role}]\n${m.content}`).join('\n\n')
  )
  const promptChecks = computed<PromptCheck[]>(() => report.value?.promptChecks ?? [])
  const failedCount = computed(
    () =>
      [...promptChecks.value, ...(report.value?.draftChecks ?? [])].filter((c) => !c.passed).length
  )

  /** 成稿层检查按「第N条」前缀归组（api 层返回的是扁平数组） */
  const draftCheckGroups = computed(() => {
    const groups: { label: string; checks: DraftCheck[] }[] = []
    for (const c of report.value?.draftChecks ?? []) {
      const m = c.label.match(/^(第[0-9一二三四五六]+条[^ ]*?)\s*([^\s]*)$/)
      const key = m ? m[1] : '全部成稿'
      let g = groups.find((x) => x.label === key)
      if (!g) {
        g = { label: key, checks: [] }
        groups.push(g)
      }
      g.checks.push(m && m[2] ? { ...c, label: m[2] } : c)
    }
    return groups
  })

  /** 本卡在 api 报告里的检查项前缀（api 层固定用「第N条」，见 ai.ts 的 `${n}字数` 等） */
  function draftKey(index: number): string {
    return `第${index + 1}条`
  }

  /**
   * 取本卡的质检项：**按 v-for 的 index 取**，不按 draft.id 反查 ——
   * 草稿 id 是 api 的语义 id（real/pro/convert），历史记录与收藏载入时并不存在，
   * 按下标取才不会出现「载入历史后卡片显示不到质检项」的错位。
   */
  function draftChecks(index: number): DraftCheck[] {
    const key = draftKey(index)
    return (report.value?.draftChecks ?? []).filter((c) => c.label.startsWith(key))
  }

  function draftFailedCount(index: number): number {
    return draftChecks(index).filter((c) => !c.passed).length
  }

  async function openHistory() {
    historyVisible.value = true
    historyLoading.value = true
    try {
      history.value = await fetchMarketingHistory('朋友圈')
    } finally {
      historyLoading.value = false
    }
  }

  async function removeHistory(id: number) {
    await removeMarketingHistory(id)
    history.value = history.value.filter((h) => h.id !== id)
  }

  function loadHistory(h: MarketingHistoryItem) {
    drafts.value = [
      { key: 'loaded-1', label: '历史记录', content: h.content, reply: h.reply || '' }
    ]
    source.value = h.source === 'llm' ? 'llm' : 'fallback'
    historyVisible.value = false
    warning.value = ''
    report.value = null
    promptMessages.value = []
    // 载入历史不是一次带图生成，上一条的读图提醒不能留在屏幕上误导用户
    visionHint.value = ''
  }

  function buildPersona() {
    return {
      name: my.value.name,
      gender: my.value.gender,
      age: my.value.age,
      role: persona.value.role,
      years: persona.value.years,
      specialties: persona.value.specialties,
      brand: BRAND_NAME,
      city: BRAND_CITY,
      audiences: persona.value.audiences
    }
  }

  /**
   * 把界面选择组装成 api 层入参。
   *
   * 返回类型就是 api 的 `MomentsInput`：字段名/类型漂移由 `vue-tsc` 在编译期暴露，
   * 不再有 `Record<string, unknown>` 那种「签名放宽后静默错传」的余地。
   * id 口径（goalId / lengthId / emojiUsageId / inspirationId）**恒定下发**：
   * api 层优先用 id 解析，label 仅作历史兼容与展示，因此改文案不会退回字符串匹配。
   */
  function buildPayload(): MomentsInput {
    const goal = currentGoal.value
    const emoji = currentEmoji.value
    const inspiration = currentInspiration.value
    const payload: MomentsInput = {
      persona: buildPersona(),
      category: currentGroup.value.label,
      topic: inspiration.label,
      tone: tone.value,
      goal: goal.label,
      expression: expression.value,
      lengthLabel: isCustomLength.value ? MOMENT_LENGTH_CUSTOM : currentLength.value.label,
      multiLine: multiLine.value,
      emojiLevel: emoji.label,
      userInput: userInput.value,
      goalId: goal.id,
      emojiUsageId: emoji.id,
      inspirationId: inspiration.id,
      antiFoldEnabled: antiFoldEnabled.value,
      imageCount: uploadFileList.value.length,
      draftCount: draftCount.value
    }
    if (isCustomLength.value) {
      payload.customLength = { min: customLengthMin.value, max: customLengthMax.value }
    } else {
      payload.lengthId = currentLength.value.id
    }
    // 图片说明只在真有图时下发（api 层据此注入「图片信息」行）
    if (payload.imageCount) payload.imageSummary = imageSummary.value
    /**
     * 真读图载荷：仅在**确实有已压缩好的图**时才加 `imageUrls` 键。
     * 无图时连键都不出现（api 层的多模态分支因此完全不触发，请求体与旧版逐字节一致）。
     */
    const attached = imageUrls.value
    if (attached.length) payload.imageUrls = [...attached]
    return payload
  }

  async function generate() {
    if (!userInput.value.trim() && !inspirationId.value) {
      ElMessage.warning('请先选择案例/选题，或在输入框写下想表达的内容')
      return
    }
    if (isCustomLength.value && customLengthMax.value <= customLengthMin.value) {
      ElMessage.warning('自定义字数范围无效：最多字数需大于最少字数')
      return
    }
    // 还有图在压缩：此时生成会静默漏图（界面有预览、模型收不到），必须先等压缩结束
    if (compressingCount.value) {
      ElMessage.warning('图片还在压缩，请稍等一下再生成')
      return
    }
    generating.value = true
    warning.value = ''
    visionHint.value = ''
    try {
      const payload = buildPayload()
      const res = await generateMomentsCopy(payload)
      drafts.value = toDraftRows(res.drafts)
      source.value = res.source
      warning.value = res.warning ?? ''
      // 引擎返回的「当前模型可能不读图」提醒：视图只转发，不自己重算能力判定
      visionHint.value = res.visionHint ?? ''
      promptMessages.value = res.prompt
      report.value = auditMomentsResult(payload, promptText.value, res.drafts)
    } finally {
      generating.value = false
    }
  }

  /** 编辑某条后重算质检：仍走 api 层，不在视图里自算 */
  function recount() {
    report.value = auditMomentsResult(buildPayload(), promptText.value, toAuditDrafts(drafts.value))
  }

  const draftEditVisible = ref(false)
  const draftEditIndex = ref(0)
  const draftEdit = ref({ content: '', reply: '' })

  function openDraftEditor(index: number) {
    draftEditIndex.value = index
    draftEdit.value = {
      content: drafts.value[index]?.content ?? '',
      reply: drafts.value[index]?.reply ?? ''
    }
    draftEditVisible.value = true
  }

  function applyDraftEdit() {
    const d = drafts.value[draftEditIndex.value]
    if (d) {
      d.content = draftEdit.value.content
      d.reply = draftEdit.value.reply
    }
    draftEditVisible.value = false
    recount()
  }

  async function copyText(text: string) {
    try {
      await navigator.clipboard.writeText(text)
      ElMessage.success('已复制')
    } catch {
      ElMessage.warning('复制失败，请手动选择复制')
    }
  }

  function copyDraft(d: MktDraftRow) {
    copyText(d.reply ? `${d.content}\n\n——评论区首条回复——\n${d.reply}` : d.content)
  }

  function copyAll() {
    const parts = drafts.value.map((d) => {
      const body = d.reply ? `${d.content}\n\n——评论区首条回复——\n${d.reply}` : d.content
      return `【${d.label}】\n${body}`
    })
    copyText(parts.join('\n\n'))
  }

  function currentTitle() {
    return (
      inspirationItems.value.find((it) => it.id === inspirationId.value)?.label ||
      userInput.value.slice(0, 16) ||
      '自由创作'
    )
  }

  /**
   * 收藏到朋友圈库：**先写服务端，成功才落缓存**（store 负责）。
   * 失败时不提示 —— 提示已由 api 层按错误类型给出（上限类透出后端原文），
   * 这里再弹一次会变成同一错误弹两次。
   */
  async function saveOneFavorite(d: MktDraftRow) {
    if (!d.content.trim()) return
    const ok = await saveFavorite({
      platform: '朋友圈',
      title: drafts.value.length > 1 ? `${currentTitle()} · ${d.label}` : currentTitle(),
      content: d.content,
      // 首评独立入库（服务端已有 reply 列）；老收藏没有该字段时界面不显示空壳
      reply: d.reply,
      tags: []
    })
    if (ok) ElMessage.success(`已收藏「${d.label}」到朋友圈库`)
  }

  // 服务端缺失字段为 null，老数据可能连键都没有 —— 这里统一收成 ''，不让 null 进模板
  function loadFavorite(f: { content: string; reply?: string | null }) {
    drafts.value = [
      { key: 'loaded-1', label: '收藏文案', content: f.content, reply: f.reply ?? '' }
    ]
    source.value = 'fallback'
    report.value = null
    promptMessages.value = []
    visionHint.value = ''
    favVisible.value = false
  }

  // ==================== 收藏的服务端同步（拉取 / 旧收藏导入 / 反馈） ====================

  const {
    loading: favLoading,
    origin: favOrigin,
    offlineText: favOfflineText,
    refresh: refreshFavorites,
    saveFavorite
  } = useMarketingFavorites()

  /** 打开抽屉：强制对齐云端 + 跑一次可见反馈的旧收藏导入（辅触发） */
  async function onFavoritesOpen() {
    await refreshFavorites()
  }

  /** 删除：失败时列表保持不动（store 已 console.warn），不弹错误打扰用户 */
  async function onRemoveFavorite(id: number) {
    const ok = await aiStore.removeFavorite(id)
    if (ok) ElMessage.success('已删除')
  }
</script>
