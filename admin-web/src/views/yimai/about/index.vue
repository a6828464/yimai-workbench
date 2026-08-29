<template>
  <div class="about-page mx-auto max-w-980 pt-8 pb-12 px-4">
    <!-- 封面 -->
    <div class="rounded-2xl p-8 text-white mb-6" style="background: linear-gradient(135deg, #2f7d5d, #1d5c43)">
      <div class="flex items-center gap-3 mb-3">
        <img src="@imgs/yimai-logo.png" class="w-12 h-12 object-contain rounded-lg bg-white/90 p-1" alt="一麦" />
        <div>
          <h1 class="text-2xl font-600 leading-none">一麦管理工作台</h1>
          <p class="mt-1.5 text-sm opacity-80">一麦瑜伽 · 双店内部经营与服务执行平台</p>
        </div>
      </div>
      <p class="text-sm leading-6 opacity-90 mt-4">
        面向超管 / 店长 / 老师 / 新媒体四类角色的内部工作台：把客资承接、体验成交、会员维护、
        续费预警、任务审批放进一个闭环，AI 内容生成作为效率工具嵌入。
      </p>
    </div>

    <!-- 角色与权限 -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
      <ElCard shadow="never" class="!rounded-xl">
        <template #header><span class="font-500">四类角色</span></template>
        <div class="text-sm leading-7 text-g-600">
          <p><b>超管</b>：全功能 + 数据看板 / 同步 / 人员 / 模型配置</p>
          <p><b>店长</b>：本店数据 / 会员五清单 / 任务审批 / 谈单工具</p>
          <p><b>老师</b>：我的会员 / 留资跟进 / 训练计划</p>
          <p><b>新媒体</b>：留资录入 / 营销工具 / 经营看板</p>
        </div>
      </ElCard>
      <ElCard shadow="never" class="!rounded-xl">
        <template #header><span class="font-500">技术栈</span></template>
        <div class="text-sm leading-7 text-g-600">
          <p>前端：Vue 3.5 + TypeScript + Vite + Element Plus + Tailwind</p>
          <p>后端：Laravel 12 + MySQL 8（Sanctum 令牌认证）</p>
          <p>数据接入：随心瑜云 KeepYoga（只读，服务端代理）</p>
          <p>AI：OpenAI 兼容协议（DeepSeek / 通义 / Kimi / GLM / 豆包）</p>
        </div>
      </ElCard>
    </div>

    <!-- 功能模块 -->
    <ElCard shadow="never" class="!rounded-xl mb-6">
      <template #header><span class="font-500">功能模块</span></template>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-2 text-sm text-g-700">
        <p v-for="m in MODULES" :key="m" class="flex-c gap-2">
          <i class="ri-check-line text-theme" />
          {{ m }}
        </p>
      </div>
    </ElCard>

    <!-- 使用帮助 -->
    <ElCard shadow="never" class="!rounded-xl mb-6">
      <template #header><span class="font-500">常见使用</span></template>
      <ul class="text-sm leading-7 text-g-600 list-disc pl-5 space-y-1">
        <li>开始：登录后在左侧菜单进入各功能；右上角 AI 助手可随时提问。</li>
        <li>会员：五清单自动归入，可调整标签阈值；客户经营池做全景筛选。</li>
        <li>留资：新媒体录入 → 店长分配 → 老师跟进，全流程留痕可溯源。</li>
        <li>营销：AI 生成朋友圈/小红书草稿，人工确认后发布；谈单分享页可在微信打开。</li>
        <li>同步：超管在「KeepYoga同步」配置账号并全量导入会员。</li>
        <li>更新：超管在「版本更新」查看更新日志与在线更新。</li>
      </ul>
    </ElCard>

    <p class="text-center text-xs text-gray-400">一麦管理工作台 · 版本 1.0 · 仅供内部使用</p>
  </div>
</template>

<script setup lang="ts">
  defineOptions({ name: 'YimaiAbout' })

  const MODULES = [
    '工作台（四角色数据仪表盘）',
    '会员管理（五清单引擎）',
    '留资管理（客资承接）',
    '客户经营池（超管全景）',
    '任务中心（分派/验收）',
    '价格审批（两级审批）',
    '训练计划（AI草稿 + H5分享）',
    '谈单工具（门店/产品/教练/案例）',
    '营销工具（AI文案生成）',
    'KeepYoga同步（会员导入）',
    '经营看板 / 操作留痕',
    '人员管理 / 模型配置'
  ]
</script>