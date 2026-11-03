<script setup lang="ts">
import { onLaunch } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'

onLaunch(() => {
  const user = useUserStore()
  user.restore()
  if (!user.token) {
    uni.reLaunch({ url: '/pages/login/login' })
  }
})
</script>

<style lang="scss">
@import './styles/common.scss';
</style>
