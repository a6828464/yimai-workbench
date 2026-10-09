<template>
  <div>
    <!-- 账号定位与人设（保存到账号，多设备共用） -->
    <ElCard shadow="never" class="mb-4">
      <template #header>
        <div class="mk-card-head">
          <span class="font-500">账号定位与人设</span>
          <div class="flex-c gap-3 flex-wrap">
            <span class="text-xs text-gray-400"
              >品牌固定「{{ BRAND_NAME }}」· 城市固定「{{ BRAND_CITY }}」</span
            >
            <ElButton
              size="small"
              type="primary"
              plain
              :loading="savingProfile"
              @click="saveProfile"
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
        <ElCol :xs="24" :md="12">
          <ElFormItem :label-position="formLabelPosition" label="IP定位">
            <div class="mk-ip-row">
              <ElRadioGroup v-model="profile.ipType">
                <ElRadioButton v-for="v in IP_TYPE_OPTIONS" :key="v" :value="v">
                  {{ v }}
                </ElRadioButton>
              </ElRadioGroup>
              <!-- 建议按钮：只提示、不覆盖用户已选（Captain 裁定 §5.7） -->
              <ElButton link type="primary" size="small" @click="applyIpSuggestion">
                按角色建议：{{ ipSuggestionLabel }}
              </ElButton>
            </div>
            <!--
              IP 定位自带的写作方向说明（`XHS_IP_TYPES[].desc`）：属「这条会怎么写」的口径，
              属 captain 所指「下拉选项自带的提示」，非超管不渲染；单选控件本身不受影响。
            -->
            <div v-if="isSuper && currentIpDesc" class="text-xs text-g-500 mt-1 leading-5">
              {{ currentIpDesc }}
            </div>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="6"
          ><ElFormItem :label-position="formLabelPosition" label="账号名"
            ><ElInput v-model="profile.accountName" placeholder="非必填" /></ElFormItem
        ></ElCol>
        <ElCol :xs="12" :md="6">
          <ElFormItem :label-position="formLabelPosition" label="身份角色">
            <ElSelect v-model="profile.role">
              <ElOption v-for="r in PERSONA_ROLES" :key="r" :label="r" :value="r" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="12" :md="4">
          <ElFormItem :label-position="formLabelPosition" label="同城属性">
            <ElSwitch v-model="profile.localFocus" />
            <!-- 同城处理结论：属「系统会怎么生成」，非超管不渲染（开关本身仍在，用户照常能选） -->
            <div v-if="isSuper" class="text-xs text-g-500 mt-1 leading-5">
              {{ localScenario ? '本次按同城账号处理' : '本次不强化同城' }}
            </div>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="10">
          <ElFormItem
            :label-position="formLabelPosition"
            :label="`专业（最多 ${XHS_STRENGTH_MAX} 项）`"
          >
            <ElSelect
              v-model="specialties"
              multiple
              filterable
              allow-create
              default-first-option
              collapse-tags
              :max-collapse-tags="6"
              :multiple-limit="XHS_STRENGTH_MAX"
              class="w-full"
              :placeholder="`与个人中心·会员管理共用，最多 ${XHS_STRENGTH_MAX} 项，可自定义`"
            >
              <ElOption v-for="s in SPECIALTY_OPTIONS" :key="s" :label="s" :value="s" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="14">
          <ElFormItem
            :label-position="formLabelPosition"
            :label="`客户画像（最多 ${XHS_AUDIENCE_MAX} 项）`"
          >
            <ElSelect
              v-model="profile.audiences"
              multiple
              filterable
              allow-create
              default-first-option
              :multiple-limit="XHS_AUDIENCE_MAX"
              collapse-tags
              class="w-full"
              :placeholder="`最多选 ${XHS_AUDIENCE_MAX} 项（可自定义添加）`"
            >
              <ElOption v-for="a in AUDIENCE_OPTIONS" :key="a" :label="a" :value="a" />
            </ElSelect>
            <!--
              选到上限后再加：明确提示，绝不静默丢弃（规格 X-05 / Captain 裁定）。
              **分两半**：前半（超出的 3 项不生效 + 一键修剪按钮）是用户的数据完整性提示，
              任何角色都要留 —— 藏起来会变成「我明明存了 6 项，为什么没生效」的静默丢数据；
              括注里的「提示词只取前 N 项」是生成口径，非超管不渲染。
            -->
            <div v-if="audienceOverflow" class="mk-overflow-tip">
              已保存 {{ profile.audiences.length }} 项，超出上限的
              {{ profile.audiences.length - XHS_AUDIENCE_MAX }}
              项<b>不会进入本次生成</b
              ><span v-if="isSuper">（提示词只取前 {{ XHS_AUDIENCE_MAX }} 项）</span
              >。请删掉用不到的项。
              <ElButton link type="danger" size="small" @click="trimAudiences">
                只保留前 {{ XHS_AUDIENCE_MAX }} 项
              </ElButton>
            </div>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="12">
          <ElFormItem
            :label-position="formLabelPosition"
            :label="`表达风格（最多 ${XHS_STYLE_MAX} 项）`"
          >
            <ElSelect
              v-model="styleList"
              multiple
              collapse-tags
              :multiple-limit="XHS_STYLE_MAX"
              class="w-full"
              :placeholder="`最多选 ${XHS_STYLE_MAX} 项`"
            >
              <ElOption v-for="v in XHS_STYLES" :key="v" :label="v" :value="v" />
            </ElSelect>
            <!--
              多选风格真保存：api/my.ts 的 XhsPersonaPrefs 已增 styles?: string[]（t11 修复），
              保存后重开仍在；style 单选字段同步写第一项，老账号数据读取时回落 [style]。
            -->
            <div class="text-xs text-g-500 mt-1 leading-5">
              多选会随人设一起保存<span v-if="isSuper">（api 层 styles 优先于单选 style）</span>。
            </div>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="12">
          <ElFormItem :label-position="formLabelPosition" label="转化方向">
            <ElSelect v-model="profile.conversion">
              <ElOption v-for="v in XHS_CONVERSIONS" :key="v" :label="v" :value="v" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="12">
          <ElFormItem :label-position="formLabelPosition" label="表情符号">
            <ElSelect v-model="emojiModeId">
              <!-- 非超管看不到「每 N 字 1 个」的检验口径（与朋友圈同一常量与口径） -->
              <ElOption
                v-for="m in EMOJI_USAGE_MODES"
                :key="m.id"
                :label="emojiUsageLabel(m.label, isSuper)"
                :value="m.id"
              />
            </ElSelect>
            <!-- emoji 档位说明（每 N 字 1 个的检验口径）：属生成规则口径，非超管不渲染 -->
            <div v-if="isSuper" class="text-xs text-g-500 mt-1 leading-5">{{ emojiHint }}</div>
          </ElFormItem>
        </ElCol>
      </ElRow>
    </ElCard>

    <!-- 本次笔记 -->
    <ElCard shadow="never" class="mb-4">
      <template #header><span class="font-500">本次笔记</span></template>
      <ElRow :gutter="12">
        <ElCol :xs="24" :md="6">
          <ElFormItem :label-position="formLabelPosition" label="主题分类">
            <ElSelect v-model="groupId">
              <ElOption v-for="g in XHS_TOPIC_CATALOG" :key="g.id" :label="g.label" :value="g.id" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="10">
          <ElFormItem :label-position="formLabelPosition" label="选题模板">
            <ElSelect
              v-model="pickedTopicId"
              filterable
              clearable
              class="w-full"
              placeholder="选模板；留空＝自由发挥一个近期课堂相关选题"
            >
              <ElOption v-for="t in topicItems" :key="t.id" :label="t.label" :value="t.id" />
            </ElSelect>
          </ElFormItem>
        </ElCol>
        <ElCol :xs="24" :md="8">
          <ElFormItem :label-position="formLabelPosition" label="正文长度">
            <ElSelect v-model="lengthId">
              <!-- 非超管看不到档位里的字数区间（展示层去数字，常量仍喂提示词） -->
              <ElOption
                v-for="l in XHS_LENGTHS"
                :key="l.id"
                :label="xhsLengthLabel(l.display, isSuper)"
                :value="l.id"
              />
            </ElSelect>
            <!-- 字数口径（区间 + 上限）：与朋友圈口径块同类，非超管不渲染 -->
            <div v-if="isSuper" class="text-xs text-g-500 mt-1 leading-5">
              {{ currentLength.min }}-{{ currentLength.max }} 字（上限 {{ XHS_BODY_MAX_CHARS }} 字）
            </div>
          </ElFormItem>
        </ElCol>
        <ElCol :span="24">
          <ElFormItem :label-position="formLabelPosition" label="补充要点">
            <ElInput v-model="points" placeholder="想覆盖的信息点、活动信息（选填）" />
          </ElFormItem>
        </ElCol>
      </ElRow>
      <!--
        以下两块都是**生成口径**（选题写作方向 / 本组写法 / 生成数量 / 标题封面 / 话题标签），
        文案里的数字直接来自提示词常量；属「解释系统会怎么生成」，非超管不渲染。
        与朋友圈 tab 的口径块保持同一判据（整页口径收敛，用户只要结果）。
      -->
      <div v-if="isSuper && (topicHint || topicGuide)" class="mk-hint-block">
        <div v-if="topicHint"><b>选题写作方向：</b>{{ topicHint }}</div>
        <div v-if="topicGuide"><b>本组写法：</b>{{ topicGuide }}</div>
      </div>
      <div v-if="isSuper" class="mk-hint-block">
        <div>
          <b>生成数量：</b>一次产出 {{ XHS_NOTE_COUNT }} 篇（风格要明显不同），每篇都是「封面文案 +
          标题 + 正文 + 话题标签 + 首评」完整五件套。
        </div>
        <div>
          <b>标题/封面：</b>标题 ≤
          {{ XHS_TITLE_MAX_CHARS }} 字、封面与标题<b>不带表情符号</b>（小红书标题超
          {{ XHS_TITLE_MAX_CHARS }} 字会被截断）。
        </div>
        <div>
          <b>话题标签：</b>{{ XHS_TAG_MIN }}-{{ XHS_TAG_MAX }} 个，每个以 # 开头，需覆盖行业词 /
          问题词 / 人群词{{ localScenario ? ' / 同城词' : '' }}和本次选题词。
        </div>
      </div>
      <div class="mk-btn-row">
        <ElButton type="primary" :loading="generating" @click="generate()">生成笔记</ElButton>
        <ElButton @click="openHistory">历史生成记录</ElButton>
        <ElButton @click="favVisible = true">笔记库（{{ favorites.length }}）</ElButton>
      </div>
    </ElCard>

    <!-- 生成结果：每篇一张卡 -->
    <ElCard v-if="hasResult || warning || generating" shadow="never" class="mb-4">
      <template #header>
        <div class="mk-card-head">
          <span class="font-500">生成结果（{{ notes.length }} 篇）</span>
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
      <ElAlert
        v-if="warning"
        :title="warning"
        type="warning"
        show-icon
        :closable="false"
        class="mb-3"
      />
      <div v-loading="generating">
        <div v-if="notes.length" class="mk-result-list">
          <div v-for="(n, i) in notes" :key="`n${i + 1}`" class="mk-result-card">
            <div class="mk-result-card__head">
              <span class="mk-result-card__title">方案 {{ i + 1 }}</span>
              <!-- 三态：本卡有质检项才谈通过与否；没有就如实说没有。
                   质检徽标属「系统生成口径」的派生面，非超管整块不渲染。 -->
              <template v-if="isSuper">
                <template v-if="noteChecks(i).length">
                  <span v-if="noteFailedCount(i)" class="mk-result-card__warn"
                    >{{ noteFailedCount(i) }} 项不匹配</span
                  >
                  <span v-else class="mk-result-card__ok">质检通过</span>
                </template>
                <span v-else class="mk-result-card__none">
                  无质检数据（未匹配到方案 {{ i + 1 }}）
                </span>
              </template>
            </div>

            <!-- 封面文案：独立可见、可单独复制（规格 X-07） -->
            <div class="mk-cover-card">
              <div class="flex-cb mb-1">
                <span class="text-xs font-500 text-g-600">封面文案</span>
                <ElButton
                  link
                  type="primary"
                  size="small"
                  :disabled="!n.coverText"
                  @click="copyText(n.coverText)"
                  >复制封面</ElButton
                >
              </div>
              <p v-if="n.coverText" class="text-sm leading-6 text-g-800 whitespace-pre-wrap">{{
                n.coverText
              }}</p>
              <!-- 数据态（模型漏字段），不是能力缺失态 -->
              <p v-else class="text-xs text-g-500">本篇未返回封面文案</p>
            </div>

            <!-- 笔记正文预览 -->
            <div class="mk-note-preview">
              <h3 class="mk-note-preview__title">{{ n.title }}</h3>
              <div class="mt-1.5 text-xs text-g-500">{{ BRAND_NAME }}瑜伽 · 小红书笔记</div>
              <p class="mk-note-preview__body">{{ n.content }}</p>
              <div v-if="n.tags.length" class="px-4 pb-4 flex flex-wrap gap-1.5">
                <span v-for="tag in n.tags.slice(0, XHS_TAG_MAX)" :key="tag" class="mk-tag-pill">
                  {{ tag }}
                </span>
                <!-- 用户数据事实（共 N 个）保留：标签多到被截断，用户需要知道原因方向。
                     括注里的「建议 N-N 个」是生成口径阈值（与质检报告同源），非超管不渲染。 -->
                <span v-if="n.tags.length > XHS_TAG_MAX" class="text-xs text-g-500 self-center">
                  …共 {{ n.tags.length }} 个<span v-if="isSuper"
                    >（建议 {{ XHS_TAG_MIN }}-{{ XHS_TAG_MAX }} 个）</span
                  >
                </span>
              </div>
            </div>

            <div v-if="n.reply" class="mk-reply-card">
              <div class="flex-cb mb-1">
                <span class="text-xs font-500 text-g-600"
                  ><i class="ri-chat-3-line mr-1" />评论区首条回复建议</span
                >
                <ElButton link type="primary" size="small" @click="copyText(n.reply)"
                  >复制回复</ElButton
                >
              </div>
              <p class="text-sm leading-6 text-g-700 whitespace-pre-wrap">{{ n.reply }}</p>
            </div>

            <!-- 质检明细：来自 api 层 auditXhsResult；外层只按本卡 checks 判定。
                 明细逐条印着阈值口径，故整块仅超管可见。 -->
            <div v-if="isSuper && noteChecks(i).length" class="mk-check-list">
              <div
                v-for="c in noteChecks(i)"
                :key="c.label"
                class="mk-check-item"
                :class="{ 'is-fail': !c.passed }"
              >
                <span class="mk-check-item__label">{{ c.label }}</span>
                <span class="mk-check-item__detail">{{ c.detail || c.status }}</span>
              </div>
            </div>

            <div class="mk-btn-row mt-3">
              <ElButton type="primary" plain @click="copyNote(n)">复制整篇</ElButton>
              <ElButton plain @click="saveOneFavorite(n, i)">收藏这篇</ElButton>
              <ElButton text @click="openNoteEditor(i)">载入编辑</ElButton>
            </div>
          </div>
        </div>
        <div v-else-if="!generating" class="mk-result-empty">
          还没有结果。选择主题与选题后点「生成笔记」。
        </div>
        <div class="mk-btn-row mt-3">
          <ElButton v-if="notes.length" text @click="generate()">重新生成</ElButton>
        </div>
      </div>
    </ElCard>

    <!-- 质检报告：抽屉内逐条列着阈值口径（字数/标签数/标题上限），故整块仅超管渲染 -->
    <ElDrawer
      v-if="isSuper"
      v-model="reportVisible"
      title="质检报告 · 笔记与前端选择匹配度"
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
        <div class="mk-sub-title">提示词层</div>
        <div
          v-for="c in promptChecks"
          :key="`p-${c.label}`"
          class="mk-check-item"
          :class="{ 'is-fail': !c.passed }"
        >
          <span class="mk-check-item__label">{{ c.label }}</span>
          <span class="mk-check-item__detail">{{ c.detail || c.status }}</span>
        </div>
        <div class="mk-sub-title mt-3">每篇笔记</div>
        <div v-if="cardListMode" class="m-card-list">
          <MobileCard
            v-for="g in noteCheckGroups"
            :key="g.label"
            :title="g.label"
            :subtitle="`${g.checks.filter((c) => c.passed).length}/${g.checks.length} 项通过`"
            :tags="[
              {
                text: g.checks.every((c) => c.passed) ? '全部通过' : '存在不匹配',
                type: g.checks.every((c) => c.passed) ? 'success' : 'danger'
              }
            ]"
          >
            <div
              v-for="c in g.checks"
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
          <div v-for="g in noteCheckGroups" :key="g.label" class="mb-3">
            <div class="text-xs font-500 text-g-700 mb-1">{{ g.label }}</div>
            <div
              v-for="c in g.checks"
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

    <!-- 查看本次提示词（api 层返回的 prompt 原文，只读；仅超管可见） -->
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

    <!-- 单篇载入编辑 -->
    <ElDialog
      v-model="noteEditVisible"
      title="编辑这篇笔记"
      :width="isHandheld ? '92%' : '620px'"
      align-center
    >
      <div class="text-xs text-gray-400 mb-1">封面文案</div>
      <ElInput v-model="noteEdit.coverText" class="mb-3" />
      <div class="text-xs text-gray-400 mb-1">标题</div>
      <ElInput v-model="noteEdit.title" class="mb-3" />
      <div class="text-xs text-gray-400 mb-1">正文</div>
      <ElInput v-model="noteEdit.content" type="textarea" :rows="8" class="mb-3" />
      <div class="text-xs text-gray-400 mb-1">话题标签（空格或逗号分隔，可编辑）</div>
      <ElInput v-model="noteEdit.tagsText" class="mb-3" />
      <div class="text-xs text-gray-400 mb-1">首评建议（发布后置顶评论）</div>
      <ElInput v-model="noteEdit.reply" type="textarea" :rows="3" resize="none" />
      <template #footer>
        <div class="mk-btn-row">
          <ElButton @click="noteEditVisible = false">取消</ElButton>
          <ElButton type="primary" @click="applyNoteEdit">保存并重算质检</ElButton>
        </div>
      </template>
    </ElDialog>

    <!-- 笔记库（收藏） -->
    <ElDrawer v-model="favVisible" title="小红书笔记库" size="460px" @open="onFavoritesOpen">
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
        <div v-if="cardListMode" class="m-card-list">
          <template v-if="favorites.length">
            <MobileCard
              v-for="f in favorites"
              :key="f.id"
              :title="f.title || '未命名笔记'"
              :subtitle="f.createdAt"
              :tags="f.tags.slice(0, FAV_TAG_PREVIEW).map((t) => ({ text: t }))"
              :actions="[
                { text: '载入编辑', type: 'primary', onClick: () => loadFavorite(f) },
                {
                  text: '复制正文',
                  onClick: () => copyText(`${f.title}\n\n${f.content}\n\n${f.tags.join(' ')}`)
                },
                {
                  text: '复制封面',
                  show: !!f.coverText,
                  onClick: () => copyText(f.coverText || '')
                },
                { text: '复制首评', show: !!f.reply, onClick: () => copyText(f.reply || '') },
                { text: '删除', type: 'danger', onClick: () => onRemoveFavorite(f.id) }
              ]"
            >
              <!-- 封面文案 / 首评独立呈现（老收藏缺失时不显示空壳） -->
              <div v-if="f.coverText" class="mk-fav-cover">封面：{{ f.coverText }}</div>
              <p class="mk-card-text">{{ f.content }}</p>
              <div v-if="f.reply" class="mk-fav-reply">💬 {{ f.reply }}</div>
            </MobileCard>
          </template>
          <div v-else class="m-card-list__empty">收藏的笔记会出现在这里</div>
        </div>

        <div v-else-if="favorites.length">
          <div
            v-for="f in favorites"
            :key="f.id"
            class="mb-3 p-3 rounded-lg border border-gray-100 dark:border-gray-700"
          >
            <div class="flex-cb mb-1">
              <span class="text-sm font-500 truncate">{{ f.title }}</span>
              <ElIcon
                class="cursor-pointer shrink-0 ml-2"
                color="#f56c6c"
                @click="onRemoveFavorite(f.id)"
                ><Delete
              /></ElIcon>
            </div>
            <div class="text-xs text-gray-400 mb-1">{{ f.createdAt }}</div>
            <div v-if="f.coverText" class="mk-fav-cover">封面：{{ f.coverText }}</div>
            <div class="text-sm leading-5 whitespace-pre-wrap line-clamp-3">{{ f.content }}</div>
            <div v-if="f.reply" class="mk-fav-reply">💬 {{ f.reply }}</div>
            <div v-if="f.tags.length" class="mt-1 flex flex-wrap gap-1">
              <span v-for="t in f.tags.slice(0, FAV_TAG_PREVIEW)" :key="t" class="mk-tag-pill">{{
                t
              }}</span>
            </div>
            <div class="mt-2 flex gap-2">
              <ElButton link type="primary" size="small" @click="loadFavorite(f)"
                >载入编辑</ElButton
              >
              <ElButton
                link
                size="small"
                @click="copyText(`${f.title}\n\n${f.content}\n\n${f.tags.join(' ')}`)"
                >复制正文</ElButton
              >
              <ElButton v-if="f.coverText" link size="small" @click="copyText(f.coverText || '')"
                >复制封面</ElButton
              >
              <ElButton v-if="f.reply" link size="small" @click="copyText(f.reply || '')"
                >复制首评</ElButton
              >
            </div>
          </div>
        </div>
        <ElEmpty v-else-if="!cardListMode" description="收藏的笔记会出现在这里" />
      </div>
    </ElDrawer>

    <!-- 历史生成记录（按账号云端保存，最近300条） -->
    <ElDrawer v-model="historyVisible" title="历史生成笔记" size="460px">
      <ElAlert
        title="历史整文含封面文案（首行「【封面文案】…」）与标签，可直接回看。"
        type="info"
        show-icon
        :closable="false"
        class="mb-3"
      />
      <div v-loading="historyLoading">
        <div v-if="cardListMode" class="m-card-list">
          <template v-if="history.length">
            <MobileCard
              v-for="h in history"
              :key="h.id"
              :title="h.title || '未命名笔记'"
              :subtitle="h.createdAt"
              :note="h.reply ? `💬 ${h.reply}` : ''"
              note-label="首评"
              :actions="[
                { text: '载入编辑', type: 'primary', onClick: () => loadHistory(h) },
                { text: '复制', onClick: () => copyText(h.content) },
                { text: '删除', type: 'danger', onClick: () => removeHistory(h.id) }
              ]"
            >
              <p class="mk-card-text">{{ noteSnippet(h.content) }}</p>
            </MobileCard>
          </template>
          <div v-else-if="!historyLoading" class="m-card-list__empty">
            每次生成的笔记都会自动记录在这里
          </div>
        </div>

        <div v-else-if="history.length">
          <div
            v-for="h in history"
            :key="h.id"
            class="mb-3 p-3 rounded-lg border border-gray-100 dark:border-gray-700"
          >
            <div class="flex-cb mb-1">
              <span class="text-xs font-500 truncate">{{ h.title }}</span>
              <ElIcon
                class="cursor-pointer shrink-0 ml-2"
                color="#f56c6c"
                @click="removeHistory(h.id)"
                ><Delete
              /></ElIcon>
            </div>
            <div class="text-xs text-gray-400 mb-1">{{ h.createdAt }}</div>
            <div class="text-sm leading-5 whitespace-pre-wrap line-clamp-3">{{
              noteSnippet(h.content)
            }}</div>
            <div v-if="h.reply" class="mt-1.5 text-xs text-g-500 leading-5 line-clamp-2"
              >💬 {{ h.reply }}</div
            >
            <div class="mt-2 flex gap-2">
              <ElButton link type="primary" size="small" @click="loadHistory(h)">载入编辑</ElButton>
              <ElButton link size="small" @click="copyText(h.content)">复制</ElButton>
              <ElButton v-if="h.reply" link size="small" @click="copyText(h.reply)"
                >复制首评</ElButton
              >
            </div>
          </div>
        </div>
        <ElEmpty v-else-if="!historyLoading" description="每次生成的笔记都会自动记录在这里" />
      </div>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import {
    AUDIENCE_OPTIONS,
    BRAND_CITY,
    BRAND_NAME,
    DEFAULT_EMOJI_USAGE_ID,
    EMOJI_USAGE_MODES,
    PERSONA_ROLES,
    SPECIALTY_OPTIONS,
    XHS_AUDIENCE_MAX,
    XHS_BODY_MAX_CHARS,
    XHS_CONVERSIONS,
    XHS_LENGTHS,
    XHS_NOTE_COUNT,
    XHS_STRENGTH_MAX,
    XHS_STYLE_MAX,
    XHS_STYLES,
    XHS_TAG_MAX,
    XHS_TAG_MIN,
    XHS_TITLE_MAX_CHARS,
    XHS_TOPIC_CATALOG,
    XHS_TOPIC_WRITING_GUIDE,
    auditXhsResult,
    findEmojiUsageMode,
    findXhsIpType,
    findXhsLength,
    findXhsTopic,
    generateXhsNote,
    isXhsLocalScenario,
    noteSnippet,
    emojiUsageLabel,
    resolveXhsIpType,
    xhsLengthLabel
  } from './marketing-api-bridge'
  import type {
    PromptCheck,
    PromptLayer,
    XhsInput,
    XhsMatchReport,
    XhsNote,
    XhsProfile
  } from './marketing-api-bridge'
  import type { MarketingHistoryItem, MyProfile } from '@/api/my'
  import {
    defaultMyProfile,
    fetchMyProfile,
    saveMyProfile,
    fetchMarketingHistory,
    removeMarketingHistory
  } from '@/api/my'
  import { useMarketingFavorites } from './marketing-favorites'
  import { useAiConfigStore } from '@/store/modules/ai-config'
  import { useUserStore } from '@/store/modules/user'
  import { useDevice } from '@/hooks/core/useDevice'
  import { Delete } from '@element-plus/icons-vue'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'XhsTool' })

  const { isHandheld } = useDevice()

  /** 超管判定：同 `marketing/index.vue` 既有范式，只读服务端下发的 roles（外部不可覆盖） */
  const userStore = useUserStore()
  const isSuper = computed(() => (userStore.getUserInfo.roles ?? []).includes('R_SUPER'))

  /** 表单 label 位置：手持端（≤768）改到控件上方（本页无外层 ElForm，只能写在 form-item 上） */
  const formLabelPosition = computed<'top' | undefined>(() =>
    isHandheld.value ? 'top' : undefined
  )

  /** 「笔记库 / 历史生成记录」一条记录一张卡：手持端用 MobileCard */
  const cardListMode = computed(() => isHandheld.value)

  const aiStore = useAiConfigStore()
  const favorites = computed(() =>
    aiStore.marketing.favorites.filter((f) => f.platform === '小红书')
  )

  /** 收藏卡上的标签预览上限（展示截断，与提示词口径无关） */
  const FAV_TAG_PREVIEW = 4

  const my = ref<MyProfile>(defaultMyProfile())

  /** 中文口径是持久化字段（api/my.ts 的 XhsPersonaPrefs.ipType），字面值由 api 类型约束 */
  const IP_TYPE_OPTIONS: XhsProfile['ipType'][] = ['个人IP', '门店IP']

  /** 小红书人设编辑区 */
  const profile = ref<XhsPersonaEditor>({
    ipType: '个人IP',
    accountName: '',
    role: '全职老师',
    audiences: [],
    style: '真实接地气',
    conversion: '评论区留言',
    localFocus: true
  })
  /** 与个人中心共享的专业 */
  const specialties = ref<string[]>([])

  type XhsPersonaEditor = Omit<XhsProfile, 'gender' | 'age' | 'brand' | 'city' | 'strengths'>

  /** 表达风格：api 层口径是多选（上限 XHS_STYLE_MAX）；保存后随人设落库（my.ts styles） */
  const styleList = ref<string[]>(['真实接地气'])

  /** emoji 四档（复用朋友圈的 api 层枚举，解析走 findEmojiUsageMode） */
  const emojiModeId = ref<string>(DEFAULT_EMOJI_USAGE_ID)
  const emojiHint = computed(() => {
    const m = findEmojiUsageMode(emojiModeId.value)
    return m.charsPerSymbol
      ? `平均每 ${m.charsPerSymbol} 字 1 个 · ${m.desc}`
      : `${m.label} · ${m.desc}`
  })

  /** 当前 IP 定位说明（api 层 XHS_IP_TYPES.desc） */
  const currentIpDesc = computed(() => findXhsIpType(resolveXhsIpType(profile.value)).desc)

  /**
   * 角色 → IP 建议（管理层/主理人 → 门店IP，其余 → 个人IP）；只作建议按钮，不强制覆盖。
   * 展示用 api 的 id 口径（venue-ip / personal-ip），避免视图再维护一套中文枚举。
   */
  const ipSuggestionLabel = computed(() =>
    ['管理层', '主理人'].includes(profile.value.role) ? '门店IP' : '个人IP'
  )
  function applyIpSuggestion() {
    profile.value.ipType = ipSuggestionLabel.value === '门店IP' ? '门店IP' : '个人IP'
    ElMessage.success(
      `已按角色「${profile.value.role}」切换到 ${findXhsIpType(resolveXhsIpType(profile.value)).label}`
    )
  }

  /** 已保存的画像数超过上限：明确提示不静默（规格 X-05） */
  const audienceOverflow = computed(() => profile.value.audiences.length > XHS_AUDIENCE_MAX)
  function trimAudiences() {
    profile.value.audiences = profile.value.audiences.slice(0, XHS_AUDIENCE_MAX)
    ElMessage.success(`已只保留前 ${XHS_AUDIENCE_MAX} 项`)
  }

  onMounted(async () => {
    my.value = await fetchMyProfile()
    const { ipType, accountName, role, audiences, style, styles, conversion, localFocus } =
      my.value.xhs
    profile.value = {
      ipType,
      accountName,
      role,
      audiences: [...audiences],
      style,
      conversion,
      localFocus
    }
    // 老账号数据没有 styles：my.ts 的 normalizeXhsPrefs 已回落为 [style]
    styleList.value = (styles?.length ? styles : style ? [style] : []).slice(0, XHS_STYLE_MAX)
    specialties.value = [...my.value.specialties]
    if (audienceOverflow.value) {
      ElMessage.warning(
        `已保存 ${profile.value.audiences.length} 项客户画像，超出上限 ${XHS_AUDIENCE_MAX} 项，本次生成只会用到前 ${XHS_AUDIENCE_MAX} 项`
      )
    }
  })

  const savingProfile = ref(false)
  async function saveProfile() {
    savingProfile.value = true
    try {
      // styles 随人设真保存（api/my.ts XhsPersonaPrefs.styles），style 同步写第一项兼容旧读法
      await saveMyProfile({
        specialties: specialties.value,
        xhs: {
          ...profile.value,
          styles: styleList.value,
          style: styleList.value[0] ?? profile.value.style
        }
      })
      my.value = await fetchMyProfile(true)
      ElMessage.success('人设已保存到账号，换设备登录也不会丢')
    } catch (e) {
      ElMessage.error(`保存失败：${String(e).slice(0, 60)}`)
    } finally {
      savingProfile.value = false
    }
  }

  // ==================== 本次笔记 ====================

  /** 主题分类 = api 层选题目录的组 id（不再手写「中文名 → groupId」映射） */
  const groupId = ref<string>(XHS_TOPIC_CATALOG[0].id)
  const topicItems = computed(
    () => XHS_TOPIC_CATALOG.find((g) => g.id === groupId.value)?.items ?? []
  )
  /** 选题项 id；留空表示自由发挥（api 层按 label 空串走自由选题分支） */
  const pickedTopicId = ref('')
  /** api 查表：返回 { id, label, promptHint, groupId, groupLabel }；未选中时为 null */
  const topicItem = computed(() => (pickedTopicId.value ? findXhsTopic(pickedTopicId.value) : null))
  const topicHint = computed(() => topicItem.value?.promptHint ?? '')
  const topicGuide = computed(() =>
    topicItem.value ? (XHS_TOPIC_WRITING_GUIDE[topicItem.value.groupId] ?? '') : ''
  )

  const points = ref('')

  const lengthId = ref<string>(XHS_LENGTHS[1].id)
  const currentLength = computed(() => findXhsLength(lengthId.value))

  /**
   * 同城场景：api 层三条件（门店IP ∨ 同城开关 ∨ 同城选题组）。
   * ipType 必须走 **id 口径**（`resolveXhsIpType`）——中文 label 传进去会让「门店IP」这条
   * 条件永不命中，界面提示会与生成口径不一致（RV2 §7 活缺陷）。
   */
  const localScenario = computed(() =>
    isXhsLocalScenario({
      ipType: resolveXhsIpType(profile.value),
      localFocus: profile.value.localFocus,
      topicGroupId: topicItem.value?.groupId
    })
  )

  // ==================== 生成 ====================

  const generating = ref(false)
  const notes = ref<XhsNote[]>([])
  const source = ref<'llm' | 'fallback'>('fallback')
  const warning = ref('')
  const report = ref<XhsMatchReport | null>(null)
  const promptMessages = ref<PromptLayer[]>([])
  const favVisible = ref(false)
  const reportVisible = ref(false)
  const promptVisible = ref(false)

  const hasResult = computed(() => notes.value.length > 0)
  const promptText = computed(() =>
    promptMessages.value.map((m) => `[${m.role}]\n${m.content}`).join('\n\n')
  )
  const promptChecks = computed<PromptCheck[]>(() => report.value?.promptChecks ?? [])
  const failedCount = computed(
    () =>
      [...promptChecks.value, ...(report.value?.draftChecks ?? [])].filter((c) => !c.passed).length
  )

  /** 每篇的质检项按「方案N」前缀归组 */
  const noteCheckGroups = computed(() => {
    const groups: { label: string; checks: PromptCheck[] }[] = []
    for (const c of report.value?.draftChecks ?? []) {
      const m = c.label.match(/^(方案\s*[0-9一二三四五六]+)(.*)$/)
      const key = m ? m[1].replace(/\s/g, '') : '全部笔记'
      let g = groups.find((x) => x.label === key)
      if (!g) {
        g = { label: key, checks: [] }
        groups.push(g)
      }
      g.checks.push(m && m[2].trim() ? { ...c, label: m[2].trim() } : c)
    }
    return groups
  })

  /** 本卡质检项：按 v-for 的 index 取（api 检查项名固定为「方案N」，见 ai.ts 的 `const tag = 方案${i+1}`） */
  function noteChecks(index: number): PromptCheck[] {
    const key = `方案${index + 1}`
    return (report.value?.draftChecks ?? []).filter((c) => c.label.startsWith(key))
  }

  function noteFailedCount(index: number): number {
    return noteChecks(index).filter((c) => !c.passed).length
  }

  /**
   * 界面选择 → api 入参（返回类型就是 `XhsInput`，形状漂移由 vue-tsc 暴露）。
   * id 口径恒定下发：ipTypeId / topicId / lengthId / emojiUsageId。
   */
  function buildPayload(): XhsInput {
    const payload: XhsInput = {
      profile: {
        ...profile.value,
        // 多选风格（api 层 styles 优先）+ 单选字段同步第一项
        styles: styleList.value,
        style: styleList.value[0] ?? profile.value.style,
        // IP 走 id 口径（api 解析顺序 ipTypeId → 中文串）
        ipTypeId: resolveXhsIpType(profile.value),
        emojiUsageId: emojiModeId.value,
        gender: my.value.gender,
        age: my.value.age,
        brand: BRAND_NAME,
        city: BRAND_CITY,
        strengths: specialties.value
      },
      category: XHS_TOPIC_CATALOG.find((g) => g.id === groupId.value)?.label ?? '',
      topic: topicItem.value?.label ?? '',
      points: points.value,
      lengthId: lengthId.value,
      emojiUsageId: emojiModeId.value
    }
    if (topicItem.value) payload.topicId = topicItem.value.id
    return payload
  }

  async function generate() {
    if (!pickedTopicId.value && !points.value.trim()) {
      ElMessage.warning('请选择选题，或填写补充要点')
      return
    }
    generating.value = true
    warning.value = ''
    try {
      const payload = buildPayload()
      const res = await generateXhsNote(payload)
      notes.value = res.notes
      source.value = res.source
      warning.value = res.warning ?? ''
      promptMessages.value = res.prompt
      report.value = auditXhsResult(payload, promptText.value, res.notes)
    } finally {
      generating.value = false
    }
  }

  function recount() {
    report.value = auditXhsResult(buildPayload(), promptText.value, notes.value)
  }

  const noteEditVisible = ref(false)
  const noteEditIndex = ref(0)
  const noteEdit = ref({ title: '', coverText: '', content: '', tagsText: '', reply: '' })

  function openNoteEditor(index: number) {
    const n = notes.value[index]
    noteEditIndex.value = index
    noteEdit.value = {
      title: n?.title ?? '',
      coverText: n?.coverText ?? '',
      content: n?.content ?? '',
      tagsText: (n?.tags ?? []).join(' '),
      reply: n?.reply ?? ''
    }
    noteEditVisible.value = true
  }

  function applyNoteEdit() {
    const n = notes.value[noteEditIndex.value]
    if (n) {
      n.title = noteEdit.value.title
      n.coverText = noteEdit.value.coverText
      n.content = noteEdit.value.content
      n.tags = noteEdit.value.tagsText.split(/[\s,，]+/).filter(Boolean)
      n.reply = noteEdit.value.reply
    }
    noteEditVisible.value = false
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

  /** 复制整篇：封面文案 → 标题 → 正文 → 标签 → 首评，顺序与发布动线一致 */
  function noteToText(n: XhsNote) {
    const parts = [
      n.coverText ? `【封面文案】${n.coverText}` : '',
      n.title,
      '',
      n.content,
      '',
      n.tags.join(' ')
    ]
    return n.reply ? `${parts.join('\n')}\n\n——评论区首条回复——\n${n.reply}` : parts.join('\n')
  }

  function copyNote(n: XhsNote) {
    copyText(noteToText(n))
  }

  /**
   * 收藏：`ai-config` 的 FavoriteItem 只有 title/content/tags（无 coverText / reply 字段），
   * 因此收藏不含封面文案与首评 —— 这一点已在笔记库抽屉顶部明确写出，不做静默丢弃的假象。
   */
  /**
   * 收藏这篇笔记：**先写服务端，成功才落缓存**（store 负责）。
   * 封面文案与首评都作为独立字段入库（服务端已有 reply / coverText 列），
   * 因此「载入编辑」能还原完整五件套；老收藏缺这两个字段时界面不显示空壳。
   * 失败不在此提示 —— api 层已按错误类型给出（上限类透出后端原文），避免同一错误弹两次。
   */
  async function saveOneFavorite(n: XhsNote, index: number) {
    if (!n.title && !n.content) return
    const ok = await saveFavorite({
      platform: '小红书',
      title:
        notes.value.length > 1
          ? `方案${index + 1} · ${n.title}`
          : n.title || n.content.slice(0, 20),
      content: n.content,
      coverText: n.coverText,
      reply: n.reply,
      tags: n.tags
    })
    if (ok) ElMessage.success(`已收藏方案 ${index + 1}`)
  }

  // 服务端缺失字段为 null，老数据可能连键都没有 —— 这里统一收成 ''，不让 null 进模板
  function loadFavorite(f: {
    title: string
    content: string
    tags: string[]
    coverText?: string | null
    reply?: string | null
  }) {
    notes.value = [
      {
        title: f.title,
        coverText: f.coverText ?? '',
        content: f.content,
        tags: f.tags,
        reply: f.reply ?? ''
      }
    ]
    source.value = 'fallback'
    report.value = null
    promptMessages.value = []
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

  // ==================== 历史（封面文案在 content 首行，可回看） ====================

  const historyVisible = ref(false)
  const historyLoading = ref(false)
  const history = ref<MarketingHistoryItem[]>([])

  async function openHistory() {
    historyVisible.value = true
    historyLoading.value = true
    try {
      history.value = await fetchMarketingHistory('小红书')
    } finally {
      historyLoading.value = false
    }
  }

  async function removeHistory(id: number) {
    await removeMarketingHistory(id)
    history.value = history.value.filter((h) => h.id !== id)
  }

  function loadHistory(h: MarketingHistoryItem) {
    // 历史整文格式（api 层写入）：【封面文案】… \n\n 标题 \n\n 正文 \n\n #标签串
    const lines = h.content.split('\n')
    let coverText = ''
    if (lines[0]?.trim().startsWith('【封面文案】')) {
      coverText = lines.shift()!.replace('【封面文案】', '').trim()
    }
    const title = lines.shift() ?? ''
    let tagLine = ''
    for (let i = lines.length - 1; i >= 0; i--) {
      if (lines[i].trim().startsWith('#')) {
        tagLine = lines.splice(i, 1)[0].trim()
        break
      }
      if (lines[i].trim()) break
    }
    notes.value = [
      {
        title,
        coverText,
        content: lines.join('\n').trim(),
        tags: tagLine.split(/\s+/).filter(Boolean),
        reply: h.reply || ''
      }
    ]
    source.value = h.source === 'llm' ? 'llm' : 'fallback'
    historyVisible.value = false
    warning.value = ''
    report.value = null
    promptMessages.value = []
  }
</script>
