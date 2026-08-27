/**
 * KeepYoga 只读接入（开发期服务端代理）
 * - POST /api/ky/session ：用 .env.local 中的凭据服务端登录，返回 access_token
 * - /ky/**              ：透传到 cloud.keepyoga.com（前端携带 token 调用）
 * 生产环境使用 Laravel 服务端同步；本插件仅保留给本地兼容模式。
 */
import type { Plugin } from 'vite'
import crypto from 'node:crypto'

const KY_BASE = 'https://cloud.keepyoga.com'

async function readBody(req: import('node:http').IncomingMessage): Promise<string> {
  return new Promise((resolve) => {
    let data = ''
    req.on('data', (c: Buffer) => (data += c.toString()))
    req.on('end', () => resolve(data))
  })
}

export function keepyogaProxy(): Plugin {
  return {
    name: 'keepyoga-proxy',
    configureServer(server) {
      server.middlewares.use('/api/ky/session', async (req, res) => {
        try {
          const phone = process.env.KY_PHONE
          const password = process.env.KY_PASSWORD
          if (!phone || !password) {
            res.statusCode = 500
            res.setHeader('Content-Type', 'application/json; charset=utf-8')
            res.end(JSON.stringify({ ok: false, error: '缺少 KY_PHONE/KY_PASSWORD 环境变量' }))
            return
          }
          const pwd = crypto.createHash('md5').update(password).digest('hex')
          const resp = await fetch(`${KY_BASE}/passport/api/login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ phone, pwd, keep: '1', brand_id: '', venue_id: '' }).toString()
          })
          const data = await resp.json()
          res.statusCode = 200
          res.setHeader('Content-Type', 'application/json; charset=utf-8')
          res.end(JSON.stringify({ ok: true, token: data?.data?.access_token ?? '' }))
        } catch (e) {
          res.statusCode = 502
          res.setHeader('Content-Type', 'application/json; charset=utf-8')
          res.end(JSON.stringify({ ok: false, error: String(e).slice(0, 200) }))
        }
      })

      server.middlewares.use('/ky/', async (req, res) => {
        try {
          const body = ['POST', 'PUT'].includes(req.method ?? '') ? await readBody(req) : undefined
          const target = `${KY_BASE}${req.url}`
          const headers: Record<string, string> = {}
          const ct = req.headers['content-type']
          if (ct) headers['Content-Type'] = ct
          const resp = await fetch(target, { method: req.method, headers, body })
          const text = await resp.text()
          res.statusCode = resp.status
          res.setHeader('Content-Type', resp.headers.get('content-type') ?? 'application/json; charset=utf-8')
          res.end(text)
        } catch (e) {
          res.statusCode = 502
          res.setHeader('Content-Type', 'application/json; charset=utf-8')
          res.end(JSON.stringify({ errno: -1, emsg: String(e).slice(0, 200) }))
        }
      })
    }
  }
}
