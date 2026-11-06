<script setup lang="ts">
import { onLaunch } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'

onLaunch(() => {
  const user = useUserStore()
  user.restore()
  // 首页是启动首屏（pages.json 第一项），顾客也看得到，所以这里**不再强制跳登录**：
  // 未登录就停在品牌页，员工自己点底部 slogan 进登录。已登录也从首页进，
  // 保留「先看门面再进后台」的层次，不直接甩到工作台。
})
</script>

<style lang="scss">
/* 顺序敏感：令牌 → 图标字体 → 全局类。
   tokens 必须先于 common（common 里 @import 同一份，SCSS 有缓存不会重复产出）。 */
@import './styles/tokens.scss';
@import './styles/wot-icons.scss';
@import './styles/common.scss';
</style>
