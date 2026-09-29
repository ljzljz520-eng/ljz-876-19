// 设备指纹：用于让后台区分"刷新页面"与"换设备登录"。
// 只做粗粒度环境标识(非追踪用途)，持久化在当前浏览器，刷新后保持不变。
import { ref } from 'vue'

const STORAGE_KEY = 'exam_device_id'

function generateId() {
  const nav = window.navigator
  const segments = [
    nav.userAgent || '',
    nav.language || '',
    (nav.languages || []).join(','),
    screen.width || '',
    screen.height || '',
    screen.colorDepth || '',
    new Date().getTimezoneOffset(),
    Math.random().toString(36).slice(2),
    Date.now().toString(36)
  ]
  let hash = 0
  const str = segments.join('|')
  for (let i = 0; i < str.length; i++) {
    hash = (hash << 5) - hash + str.charCodeAt(i)
    hash |= 0
  }
  return `dev_${Math.abs(hash).toString(36)}_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`
}

export function getDeviceId() {
  let id = localStorage.getItem(STORAGE_KEY)
  if (!id) {
    id = generateId()
    localStorage.setItem(STORAGE_KEY, id)
  }
  return id
}

export function useDeviceId() {
  return ref(getDeviceId())
}
