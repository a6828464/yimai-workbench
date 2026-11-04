/// <reference types="vite/client" />
/// <reference types="@dcloudio/types" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<{}, {}, any>
  export default component
}

interface ImportMetaEnv {
  /** Laravel API 基址（生产：https://oa.nbyimai.com/api） */
  readonly VITE_API_BASE?: string
  /** 微信授权登录开关：'true' 才显示入口（后端 wx-login 上线且服务器已配好密钥后开启） */
  readonly VITE_WX_LOGIN_ENABLED?: string
  /** 约课小程序 AppID（首页原生跳转目标，留空则降级提示） */
  readonly VITE_BOOK_APPID?: string
  /** 客户分享页基址（谈单工具复制链接用，形如 https://oa.nbyimai.com） */
  readonly VITE_SHARE_BASE?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
