import { ref } from 'vue'

/**
 * 断网续考保护：考试过程中的本地暂存
 * 暂存内容：答案、题目状态（未答/已答/标记）、学生端本地时间、考试/设备信息
 * key 按 学生 + 试卷 维度，localStorage 在刷新后仍在，断网期间也可读写
 */
const DEVICE_KEY = 'exam_device_id'
const draftKey = (paperId) => `exam_draft_${paperId}`

function getDeviceId() {
  let id = localStorage.getItem(DEVICE_KEY)
  if (!id) {
    id = 'dev_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10)
    localStorage.setItem(DEVICE_KEY, id)
  }
  return id
}

function deviceLabel() {
  const ua = navigator.userAgent
  let os = '未知系统'
  if (/Windows NT 10/.test(ua)) os = 'Windows'
  else if (/Android/.test(ua)) os = 'Android'
  else if (/iPhone|iPad|iPod/.test(ua)) os = 'iOS'
  else if (/Mac OS X/.test(ua)) os = 'macOS'
  else if (/Linux/.test(ua)) os = 'Linux'

  let browser = '未知浏览器'
  if (/Edg\//.test(ua)) browser = 'Edge'
  else if (/Chrome\//.test(ua)) browser = 'Chrome'
  else if (/Firefox\//.test(ua)) browser = 'Firefox'
  else if (/Safari\//.test(ua)) browser = 'Safari'

  return `${os} / ${browser}`
}

export function useExamDraft(paperId) {
  const deviceId = getDeviceId()
  const label = deviceLabel()

  const load = () => {
    try {
      const raw = localStorage.getItem(draftKey(paperId))
      return raw ? JSON.parse(raw) : null
    } catch (e) {
      return null
    }
  }

  const save = (data) => {
    try {
      const payload = {
        ...data,
        device_id: deviceId,
        device_label: label,
        saved_at: new Date().toISOString() // 学生端本地时间
      }
      localStorage.setItem(draftKey(paperId), JSON.stringify(payload))
    } catch (e) {
      // 存储满或隐私模式下静默失败，不影响答题
      console.warn('考试草稿暂存失败', e)
    }
  }

  const clear = () => {
    localStorage.removeItem(draftKey(paperId))
  }

  const draft = ref(load())

  return { deviceId, deviceLabel: label, draft, load, save, clear }
}
