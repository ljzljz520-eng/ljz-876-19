// 断网续考本地暂存：
//  - 已填写答案(含每题修改本地时间与题目状态)
//  - 本地时间 / 服务端时间偏移 / 截止时间
//  - 离线期间产生的事件队列(恢复后补报)
// 使用 localStorage，页面刷新、浏览器崩溃后仍可恢复。
import { reactive, watch, computed } from 'vue'

const PREFIX = 'exam_draft_'

export function draftKey(paperId) {
  return `${PREFIX}${paperId}`
}

export function hasDraft(paperId) {
  return !!localStorage.getItem(draftKey(paperId))
}

export function clearDraft(paperId) {
  localStorage.removeItem(draftKey(paperId))
}

function createEmptyDraft(paperId) {
  return {
    exam_paper_id: paperId,
    exam_record_id: null,
    device_id: localStorage.getItem('exam_device_id') || null,
    // 试卷与题目快照(断网时刷新页面也能渲染并继续作答)
    examPaper: null,
    questions: [],
    // 答案: { [questionId]: { value: string|string[], updatedAt: number, status: string } }
    answers: {},
    // 时间锚点
    startedAt: null,          // 开考本地时间(ms)
    deadlineAt: null,         // 服务端截止时间(ms, epoch)
    serverTimeOffset: 0,      // serverTime - localTime
    savedAt: null,            // 最近一次本地暂存时间(ms)
    lastSyncAt: null,         // 最近一次成功同步到服务端时间(ms)
    // 断网状态
    online: navigator.onLine,
    offlineSince: null,       // 本次离线起点(本地 ms)
    offlineTotal: 0,          // 累计离线秒数(由服务端结算为准，本地仅展示)
    // 离线期间待补报事件
    pendingEvents: [],
    // 本地事件计数(即使事件已补报清空也保留，用于给学生/监考展示)
    stats: {
      offline_detected: 0,
      reconnect: 0,
      page_refresh: 0,
      page_hidden: 0,
      device_switch: 0
    },
    // 服务端返回的待处理状态
    recordStatus: 'in_progress',
    reviewReason: null
  }
}

export function useExamDraft(paperId) {
  const key = draftKey(paperId)

  const load = () => {
    try {
      const raw = localStorage.getItem(key)
      if (raw) {
        const parsed = { ...createEmptyDraft(paperId), ...JSON.parse(raw) }
        parsed.stats = { ...createEmptyDraft(paperId).stats, ...(parsed.stats || {}) }
        return parsed
      }
    } catch (e) {
      console.warn('读取本地答卷失败', e)
    }
    return createEmptyDraft(paperId)
  }

  const draft = reactive(load())

  const persist = () => {
    draft.savedAt = Date.now()
    try {
      localStorage.setItem(key, JSON.stringify(draft))
    } catch (e) {
      // 存储空间不足等极端情况：降级，不影响作答
      console.warn('本地答卷暂存失败', e)
    }
  }

  // 深度监听自动落盘
  watch(draft, persist, { deep: true })

  const answerEntries = computed(() => {
    return Object.entries(draft.answers).map(([qid, data]) => ({
      question_id: parseInt(qid, 10),
      answer: Array.isArray(data.value) ? data.value.join(',') : (data.value ?? ''),
      client_updated_at: data.updatedAt,
      status: data.status || 'answered'
    }))
  })

  const answeredCount = computed(() => {
    return Object.values(draft.answers).filter(a =>
      Array.isArray(a.value) ? a.value.length > 0 : (a.value !== '' && a.value !== null && a.value !== undefined)
    ).length
  })

  function setAnswer(questionId, value) {
    const prev = draft.answers[questionId]
    draft.answers[questionId] = {
      value,
      updatedAt: Date.now(),
      status: 'answered'
    }
  }

  function setQuestionStatus(questionId, status) {
    const prev = draft.answers[questionId]
    if (prev) {
      prev.status = status
      prev.updatedAt = Date.now()
    } else {
      draft.answers[questionId] = { value: '', updatedAt: Date.now(), status }
    }
  }

  function mergeServerAnswers(savedAnswers) {
    // 服务端已保存答案与本地草稿按 client_updated_at 合并，较新者胜
    (savedAnswers || []).forEach(sa => {
      const local = draft.answers[sa.question_id]
      if (!local || (sa.client_updated_at || 0) > (local.updatedAt || 0)) {
        draft.answers[sa.question_id] = {
          value: sa.answer ?? '',
          updatedAt: sa.client_updated_at || 0,
          status: 'answered'
        }
      }
    })
  }

  function syncTimeAnchor(serverTime, deadlineAt, remaining) {
    draft.serverTimeOffset = (serverTime || Date.now()) - Date.now()
    if (deadlineAt) {
      draft.deadlineAt = deadlineAt
    } else if (remaining != null) {
      draft.deadlineAt = Date.now() + draft.serverTimeOffset + remaining * 1000
    }
  }

  function serverNow() {
    return Date.now() + draft.serverTimeOffset
  }

  function remainingMs() {
    if (!draft.deadlineAt) return null
    return draft.deadlineAt - serverNow()
  }

  function queueEvent(type, payload = {}) {
    draft.pendingEvents.push({
      type,
      client_ts: Date.now(),
      is_online: navigator.onLine ? 1 : 0,
      duration: payload.duration ?? null,
      meta: payload.meta ?? undefined
    })
    if (draft.stats[type] !== undefined) {
      draft.stats[type] += 1
    }
    persist()
  }

  function markOffline() {
    if (!draft.online) return
    draft.online = false
    draft.offlineSince = Date.now()
    queueEvent('offline_detected')
  }

  function markOnline() {
    const wasOffline = !draft.online
    let duration = null
    if (wasOffline && draft.offlineSince) {
      duration = Math.max(0, Math.round((Date.now() - draft.offlineSince) / 1000))
      queueEvent('reconnect', { duration, meta: { offline_duration: duration } })
    }
    draft.online = true
    draft.offlineSince = null
    return { wasOffline, duration }
  }

  return {
    draft,
    persist,
    answerEntries,
    answeredCount,
    setAnswer,
    setQuestionStatus,
    mergeServerAnswers,
    syncTimeAnchor,
    serverNow,
    remainingMs,
    queueEvent,
    markOffline,
    markOnline,
    clear: () => clearDraft(paperId)
  }
}
