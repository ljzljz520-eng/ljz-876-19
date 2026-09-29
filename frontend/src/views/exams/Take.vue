<template>
  <div class="space-y-6">
    <!-- 断网提示横幅 -->
    <div
      v-if="!online"
      class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 flex items-start gap-3"
    >
      <svg class="w-6 h-6 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m-2.121-10.607a6 6 0 010 8.485M12 12h.01M9.172 9.172a4 4 0 000 5.656M6.343 6.343a8 8 0 000 11.314" />
      </svg>
      <div class="flex-1">
        <p class="font-semibold text-amber-800">网络已断开，您可以继续作答</p>
        <p class="text-sm text-amber-700 mt-0.5">
          答案、本地时间和题目状态已自动保存在本机；网络恢复后将自动同步并继续考试。
          <span v-if="offlineSeconds !== null">本次断网已持续 <b>{{ formatTime(offlineSeconds) }}</b>。</span>
        </p>
      </div>
    </div>

    <!-- 重新连接中提示 -->
    <div v-if="reconnecting" class="rounded-lg border border-blue-300 bg-blue-50 px-4 py-3 text-sm text-blue-700 flex items-center gap-3">
      <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600"></div>
      网络已恢复，正在同步断网期间的答案与考试记录…
    </div>

    <!-- 待监考处理页 -->
    <div v-if="awaitingReview" class="bg-white rounded-lg shadow p-8 text-center space-y-4">
      <div class="mx-auto w-14 h-14 rounded-full bg-yellow-100 flex items-center justify-center">
        <svg class="w-8 h-8 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <h2 class="text-xl font-bold text-gray-900">已超过允许的考试时长</h2>
      <p class="text-gray-600 text-sm leading-relaxed max-w-xl mx-auto">
        您的答卷已保存。系统记录到本次考试
        <b>累计断网 {{ Math.round((draft.offline_total || 0)) }} 秒</b>、
        刷新 {{ draft.stats.page_refresh || 0 }} 次、
        设备变更 {{ draft.stats.device_switch || 0 }} 次。
        监考老师将根据这些记录判断是真实断网还是其他情况，并决定是否为您延时，
        请不要关闭本页面，结果将在此自动更新。
      </p>
      <div class="text-xs text-gray-400">等待监考老师处理中…（每 10 秒自动查询一次）</div>
      <div class="flex justify-center gap-3 pt-2">
        <button class="bg-gray-200 text-gray-700 py-2 px-4 rounded hover:bg-gray-300" @click="checkReview">立即查询</button>
        <router-link to="/records" class="bg-gray-100 text-gray-600 py-2 px-4 rounded hover:bg-gray-200">我的成绩</router-link>
      </div>
    </div>

    <template v-else>
      <div class="flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-900">{{ examPaper?.title }}</h1>
        <div class="text-lg flex items-center gap-4">
          <span class="text-sm text-gray-500">
            已答 <b :class="answeredCount === questions.length ? 'text-green-600' : 'text-indigo-600'">{{ answeredCount }}</b>/{{ questions.length }}
            <span v-if="(draft.offline_total || 0) > 0" class="ml-2 text-amber-600">累计断网 {{ formatTime(draft.offline_total) }}</span>
          </span>
          <div>
            剩余时间:
            <span class="font-mono font-bold" :class="{'text-red-600': timeRemaining < 60 && timeRemaining > 0}">{{ formatTime(timeRemaining) }}</span>
          </div>
        </div>
      </div>

      <div v-if="loading" class="text-center py-8">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
      </div>

      <div v-else-if="!loading && questions.length > 0" class="space-y-8">
        <div v-for="(question, index) in questions" :key="question.id" class="bg-white rounded-lg shadow p-6">
          <div class="flex items-start mb-4">
            <span class="bg-indigo-100 text-indigo-800 text-sm font-medium px-2.5 py-0.5 rounded mr-3">{{ index + 1 }}</span>
            <div class="flex-1">
              <h3 class="text-lg font-medium text-gray-900 mb-2">{{ question.title }}</h3>
              <p class="text-sm text-gray-500 mb-3">分值: {{ question.score }}分 | 题型: {{ questionTypeLabel(question.type) }}</p>
              <div class="space-y-2">
                <!-- 单选题 -->
                <template v-if="question.type === 'single_choice'">
                  <label v-for="(label, key) in question.options" :key="key" class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50" :class="{'border-indigo-500 bg-indigo-50': getAnswer(question.id) === key}">
                    <input type="radio" :name="'question_' + question.id" :value="key" :checked="getAnswer(question.id) === key" @change="setAnswerValue(question.id, key)" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                    <span class="ml-3">{{ key }}. {{ label }}</span>
                  </label>
                </template>
                <!-- 判断题 -->
                <template v-else-if="question.type === 'true_false'">
                  <label class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50" :class="{'border-indigo-500 bg-indigo-50': getAnswer(question.id) === 'true'}">
                    <input type="radio" :name="'question_' + question.id" value="true" :checked="getAnswer(question.id) === 'true'" @change="setAnswerValue(question.id, 'true')" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                    <span class="ml-3">正确</span>
                  </label>
                  <label class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50" :class="{'border-indigo-500 bg-indigo-50': getAnswer(question.id) === 'false'}">
                    <input type="radio" :name="'question_' + question.id" value="false" :checked="getAnswer(question.id) === 'false'" @change="setAnswerValue(question.id, 'false')" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                    <span class="ml-3">错误</span>
                  </label>
                </template>
                <!-- 多选题 -->
                <template v-else-if="question.type === 'multiple_choice'">
                  <label v-for="(label, key) in question.options" :key="key" class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50" :class="{'border-indigo-500 bg-indigo-50': (getAnswer(question.id) || []).includes(key)}">
                    <input type="checkbox" :value="key" :checked="(getAnswer(question.id) || []).includes(key)" @change="toggleMultipleChoice(question.id, key)" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                    <span class="ml-3">{{ key }}. {{ label }}</span>
                  </label>
                </template>
                <!-- 填空题/问答题 -->
                <template v-else>
                  <textarea :value="getAnswer(question.id) || ''" @input="setAnswerValue(question.id, $event.target.value)" rows="3" class="w-full border border-gray-300 rounded-md p-3 focus:ring-indigo-500 focus:border-indigo-500" placeholder="请输入答案（断网时内容会暂存在本机）"></textarea>
                </template>
              </div>
            </div>
          </div>
        </div>
        <div class="flex justify-between">
          <button class="bg-gray-300 text-gray-700 py-2 px-4 rounded hover:bg-gray-400" @click="leaveExam">返回</button>
          <button @click="submitExam(false)" :disabled="submitting" class="bg-indigo-600 text-white py-2 px-6 rounded hover:bg-indigo-700 disabled:opacity-50">
            {{ submitting ? '提交中...' : '提交答卷' }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import api from '../../api'
import { useModal } from '../../composables/useModal'
import { useToast } from '../../composables/useToast'
import { getDeviceId } from '../../composables/useDeviceFingerprint'
import { useExamDraft } from '../../composables/useExamDraft'

const route = useRoute()
const router = useRouter()
const { alert } = useModal()
const { success: toastSuccess } = useToast()

const paperId = route.params.id
const deviceId = getDeviceId()

const {
  draft,
  persist,
  answerEntries,
  answeredCount,
  setAnswer,
  mergeServerAnswers,
  syncTimeAnchor,
  queueEvent,
  markOffline,
  markOnline,
  clear: clearDraft
} = useExamDraft(paperId)

const examPaper = ref(null)
const questions = ref([])
const loading = ref(true)
const submitting = ref(false)
const autoSubmitFinished = ref(false)
const online = ref(navigator.onLine)
const reconnecting = ref(false)
const awaitingReview = ref(false)
const offlineSeconds = ref(null)

const timeRemaining = ref(0)

// ---- 计时：以服务端 deadline 为准，不信任本地改钟 ----
let tickTimer = null
let heartbeatTimer = null
let autosaveTimer = null
let offlineTimer = null
let reviewPollTimer = null
let waitNetTimer = null

function startTicker() {
  if (tickTimer) clearInterval(tickTimer)
  tickTimer = setInterval(() => {
    if (!draft.deadlineAt || awaitingReview.value) return
    const remain = Math.max(0, Math.round((draft.deadlineAt - (Date.now() + draft.serverTimeOffset)) / 1000))
    timeRemaining.value = remain

    // 断网时也照常倒计时；到点自动交卷(离线则暂存，恢复后立即补提交)。
    // autoSubmitFinished 保证只触发一次，避免重复弹窗/重复请求。
    if (remain <= 0 && !submitting.value && !autoSubmitFinished.value) {
      autoSubmitFinished.value = true
      submitExam(true)
    }
  }, 1000)
}

function formatTime(seconds) {
  seconds = Math.max(0, Math.floor(seconds || 0))
  const mins = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
}

function questionTypeLabel(type) {
  const labels = {
    single_choice: '单选题',
    multiple_choice: '多选题',
    true_false: '判断题',
    fill_blank: '填空题',
    essay: '问答题'
  }
  return labels[type] || type
}

// ---- 答案读写 ----
function getAnswer(questionId) {
  return draft.answers[questionId]?.value ?? ''
}

function setAnswerValue(questionId, value) {
  setAnswer(questionId, value)
  scheduleSave()
}

function toggleMultipleChoice(questionId, key) {
  const current = Array.isArray(getAnswer(questionId)) ? [...getAnswer(questionId)] : []
  const idx = current.indexOf(key)
  if (idx === -1) current.push(key)
  else current.splice(idx, 1)
  setAnswerValue(questionId, current)
}

// ---- 网络状态 ----
function handleOffline() {
  online.value = false
  offlineSeconds.value = 0
  markOffline()
  if (offlineTimer) clearInterval(offlineTimer)
  offlineTimer = setInterval(() => {
    if (draft.offlineSince) {
      offlineSeconds.value = Math.round((Date.now() - draft.offlineSince) / 1000)
    }
  }, 1000)
}

async function handleOnline() {
  const offlineSince = draft.offlineSince // markOnline 会清空，先留存
  const { wasOffline, duration } = markOnline()
  online.value = true
  if (offlineTimer) { clearInterval(offlineTimer); offlineTimer = null }
  offlineSeconds.value = null
  if (wasOffline) {
    await handleReconnect(duration, offlineSince)
  }
}

// ---- 核心：恢复连接后同步答案 + 事件，并续考 ----
async function handleReconnect(offlineDuration = 0, offlineSince = null) {
  reconnecting.value = true
  try {
    const payload = {
      device_id: deviceId,
      reason: 'reconnect',
      offline_since_ts: offlineSince || undefined,
      client_ts: Date.now()
    }
    const res = await api.post(`/exams/${paperId}/resume`, payload)

    if (res.data.record_status === 'awaiting_review') {
      awaitingReview.value = true
      startReviewPolling()
      return
    }

    applyResumeData(res.data)
    draft.stats.reconnect = (draft.stats.reconnect || 0) + 1
    if (res.data.device_switched) {
      draft.stats.device_switch = (draft.stats.device_switch || 0) + 1
    }

    // 1) 补报离线期间缓存的事件
    if (draft.pendingEvents.length > 0) {
      try {
        await api.post(`/exams/${paperId}/events`, {
          device_id: deviceId,
          events: draft.pendingEvents.slice(0, 50)
        })
        draft.pendingEvents = []
      } catch (e) { /* 保留事件，下次重试 */ }
    }

    // 2) 同步断网期间填写的全部答案
    if (answerEntries.value.length > 0) {
      await syncAnswers(true)
    }

    toastSuccess(`网络已恢复，答案已同步（本次断网约 ${offlineDuration} 秒）`)
  } catch (e) {
    if (e.response?.status === 409 && e.response?.data?.record_status === 'awaiting_review') {
      awaitingReview.value = true
      draft.recordStatus = 'awaiting_review'
      draft.reviewReason = e.response.data.review_reason
      startReviewPolling()
    } else {
      console.error('恢复续考失败，将保留本地暂存', e)
    }
  } finally {
    reconnecting.value = false
  }
}

function applyResumeData(data) {
  examPaper.value = data.exam_paper
  questions.value = data.questions || []
  draft.examPaper = data.exam_paper
  draft.questions = data.questions || []
  draft.exam_record_id = data.exam_record?.id
  draft.recordStatus = data.exam_record?.status || 'in_progress'
  draft.reviewReason = data.exam_record?.review_reason || null
  draft.offline_total = data.exam_record?.offline_total ?? draft.offline_total ?? 0

  syncTimeAnchor(data.server_time, data.exam_record?.deadline_at, data.remaining)
  if (data.remaining != null) timeRemaining.value = data.remaining

  // 合并：本地更新的答案优先，本地没有的用服务端恢复
  mergeServerAnswers(data.saved_answers)
  normalizeAnswerFormats()
  persist()
}

// 多选题在本地以数组表示，而服务端持久化为逗号字符串，恢复时统一转换
function normalizeAnswerFormats() {
  questions.value.forEach(q => {
    if (q.type !== 'multiple_choice') return
    const entry = draft.answers[q.id]
    if (entry && typeof entry.value === 'string') {
      entry.value = entry.value === '' ? [] : entry.value.split(',').filter(Boolean)
    }
  })
}

// ---- 心跳 ----
function startHeartbeat() {
  if (heartbeatTimer) clearInterval(heartbeatTimer)
    heartbeatTimer = setInterval(async () => {
    if (!online.value || awaitingReview.value) return
    try {
      const res = await api.post(`/exams/${paperId}/heartbeat`, {
        device_id: deviceId,
        client_ts: Date.now(),
        answered_count: answeredCount.value
      })
      syncTimeAnchor(res.data.server_time, res.data.deadline_at, null)
      if (res.data.record_status === 'awaiting_review') {
        awaitingReview.value = true
        startReviewPolling()
      }
    } catch (e) {
      // 单次心跳失败不立即判离线，由 window 事件/连续失败处理
      if (e.code === 'ERR_NETWORK') handleOffline()
    }
  }, 15000)
}

// ---- 自动保存（在线时每 20s / 答题后防抖）----
let saveDebounce = null
function scheduleSave() {
  if (saveDebounce) clearTimeout(saveDebounce)
  saveDebounce = setTimeout(() => { if (online.value) syncAnswers(false) }, 2000)
}

function startAutosave() {
  if (autosaveTimer) clearInterval(autosaveTimer)
  autosaveTimer = setInterval(() => {
    if (online.value && !awaitingReview.value && answerEntries.value.length > 0) {
      syncAnswers(false)
    }
  }, 20000)
}

async function syncAnswers(showToast) {
  if (answerEntries.value.length === 0) return
  try {
    const res = await api.post(`/exams/${paperId}/sync-answers`, {
      device_id: deviceId,
      client_ts: Date.now(),
      answers: answerEntries.value
    })
    draft.lastSyncAt = Date.now()
    syncTimeAnchor(res.data.server_time, null, null)
    if (showToast) toastSuccess('答案已同步到服务器')
  } catch (e) {
    if (e.code === 'ERR_NETWORK' || !navigator.onLine) {
      handleOffline()
    }
  }
}

// ---- 页面可见性：切出/回来登记事件，用于甄别刷新与离开 ----
function handleVisibility() {
  if (!online.value || awaitingReview.value) return
  if (document.visibilityState === 'hidden') {
    queueEvent('page_hidden', { meta: { remaining: timeRemaining.value } })
    flushEvents()
  } else {
    queueEvent('page_visible')
    flushEvents()
  }
}

async function flushEvents() {
  if (!online.value || draft.pendingEvents.length === 0) return
  try {
    await api.post(`/exams/${paperId}/events`, {
      device_id: deviceId,
      events: draft.pendingEvents.slice(0, 50)
    })
    draft.pendingEvents = []
  } catch (e) { /* 离线保留 */ }
}

// ---- 关闭/刷新页面拦截 + 最后一刻暂存 ----
// 刷新/重开主要由下次进入时的 resume + 设备指纹 + 心跳缺口识别；
// 这里再用 keepalive 请求尽力补一条事件，帮助后台与"真实断网"区分。
function beforeUnload(e) {
  queueEvent('page_hidden', { meta: { phase: 'beforeunload', remaining: timeRemaining.value } })
  persist()
  beaconEvents()
  e.preventDefault()
  e.returnValue = '考试仍在进行，离开可能影响您的成绩。确定离开吗？'
  return e.returnValue
}

// keepalive fetch：页面卸载时请求也不会被立即取消
function beaconEvents() {
  if (!draft.pendingEvents.length || !navigator.onLine) return
  const base = import.meta.env.VITE_API_BASE_URL || '/api'
  try {
    fetch(`${base}/exams/${paperId}/events`, {
      method: 'POST',
      keepalive: true,
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${localStorage.getItem('token') || ''}`
      },
      body: JSON.stringify({ device_id: deviceId, events: draft.pendingEvents.slice(-20) })
    }).catch(() => {})
  } catch (_) { /* 忽略：本地暂存仍在，恢复后 resume 可识别 */ }
}

// ---- 待监考处理轮询 ----
function startReviewPolling() {
  if (reviewPollTimer) clearInterval(reviewPollTimer)
  reviewPollTimer = setInterval(checkReview, 10000)
}

async function checkReview() {
  try {
    // 用 resume 探测老师是否已延时；已延时则返回 200 + 新的 deadline
    const res = await api.post(`/exams/${paperId}/resume`, {
      device_id: deviceId,
      reason: 'page_refresh',
      client_ts: Date.now()
    }, { _silent: true })
    if (res.status === 200 && res.data.remaining != null) {
      awaitingReview.value = false
      autoSubmitFinished.value = false
      if (reviewPollTimer) { clearInterval(reviewPollTimer); reviewPollTimer = null }
      applyResumeData(res.data)
      await flushEvents()
      await syncAnswers(false)
      startTicker()
      startHeartbeat()
      startAutosave()
      toastSuccess('监考老师已批准延时，您可以继续作答')
    }
  } catch (e) {
    if (e.response?.status === 409 && e.response.data?.record_status) {
      // terminated / graded：考试结束
      const st = e.response.data.record_status
      if (st === 'terminated' || st === 'graded' || st === 'submitted') {
        if (reviewPollTimer) { clearInterval(reviewPollTimer); reviewPollTimer = null }
        clearDraft()
        alert('监考老师已结束本场考试，可在"我的成绩"中查看结果', '考试结束', 'warning')
        router.push('/records')
      }
    }
  }
}

// ---- 初始化：刷新/换设备后通过 resume 恢复现场 ----
onMounted(async () => {
  window.addEventListener('online', handleOnline)
  window.addEventListener('offline', handleOffline)
  document.addEventListener('visibilitychange', handleVisibility)
  window.addEventListener('beforeunload', beforeUnload)

  try {
    // 判断恢复原因：断网后重新加载 vs 普通刷新 vs 换设备
    const reason = !navigator.onLine ? 'reconnect' : 'page_refresh'
    const res = await api.post(`/exams/${paperId}/resume`, {
      device_id: deviceId,
      reason,
      offline_since_ts: draft.offlineSince || undefined,
      client_ts: Date.now()
    })

    applyResumeData(res.data)
    draft.startedAt = draft.startedAt || Date.now()

    if (reason === 'page_refresh') {
      draft.stats.page_refresh = (draft.stats.page_refresh || 0) + 1
    }
    if (res.data.device_switched) {
      draft.stats.device_switch = (draft.stats.device_switch || 0) + 1
      toastSuccess(res.data.message || '检测到设备变更，已记录但不影响继续作答')
    }

    // 进入即上报一次可见事件，便于区分纯刷新
    queueEvent('page_visible', { meta: { phase: 'mount', remaining: res.data.remaining } })
    await flushEvents()

    // 恢复后立即同步本地较新的答案
    await syncAnswers(false)

    startTicker()
    startHeartbeat()
    startAutosave()
  } catch (e) {
    if (e.response?.status === 409) {
      const st = e.response.data?.record_status
      if (st === 'awaiting_review') {
        awaitingReview.value = true
        draft.recordStatus = 'awaiting_review'
        startReviewPolling()
      } else {
        alert('该场考试已结束', '提示', 'warning')
        router.push('/records')
        return
      }
    } else if (e.code === 'ERR_NETWORK' || !navigator.onLine) {
      // 挂载时就断网：用本机暂存的试卷快照离线续考
      handleOffline()
      if (draft.deadlineAt && Array.isArray(draft.questions) && draft.questions.length > 0) {
        examPaper.value = draft.examPaper
        questions.value = draft.questions
        timeRemaining.value = Math.max(0, Math.round((draft.deadlineAt - (Date.now() + draft.serverTimeOffset)) / 1000))
        startTicker()
        startAutosave()
      } else {
        alert('当前无法连接服务器，且本机没有该场考试的暂存题目。请恢复网络后重新进入。', '网络不可用', 'warning')
        router.push('/exams')
        return
      }
    } else {
      alert(e.response?.data?.message || '获取考试信息失败', '考试加载失败', 'error')
      router.push('/exams')
      return
    }
  } finally {
    loading.value = false
  }
})

onBeforeRouteLeave(() => {
  if (!awaitingReview.value && timeRemaining.value > 0) {
    return window.confirm('考试仍在进行中，确定要离开吗？离开后可在断网保护下重新进入继续作答。')
  }
})

onUnmounted(() => {
  if (tickTimer) clearInterval(tickTimer)
  if (heartbeatTimer) clearInterval(heartbeatTimer)
  if (autosaveTimer) clearInterval(autosaveTimer)
  if (offlineTimer) clearInterval(offlineTimer)
  if (reviewPollTimer) clearInterval(reviewPollTimer)
  if (waitNetTimer) clearInterval(waitNetTimer)
  if (saveDebounce) clearTimeout(saveDebounce)
  window.removeEventListener('online', handleOnline)
  window.removeEventListener('offline', handleOffline)
  document.removeEventListener('visibilitychange', handleVisibility)
  window.removeEventListener('beforeunload', beforeUnload)
})

async function leaveExam() {
  if (timeRemaining.value > 0) {
    const ok = window.confirm('考试尚未结束，离开后答案已自动保存，可随时重新进入继续作答。确定离开？')
    if (!ok) return
  }
  if (online.value) await syncAnswers(false)
  router.push('/exams')
}

async function submitExam(autoSubmit = false) {
  if (submitting.value) return
  submitting.value = true
  persist()

  const doRequest = async () => {
    return api.post(`/exams/${paperId}/submit`, {
      exam_record_id: draft.exam_record_id,
      auto_submit: autoSubmit,
      client_ts: Date.now(),
      device_id: deviceId,
      answers: answerEntries.value
    })
  }

  try {
    let response
    if (!online.value) {
      // 离线到点：标记自动交卷意图，恢复网络后自动补提交
      queueEvent('auto_submit', { meta: { offline: true } })
      alert('考试时间到。检测到当前处于断网状态，答卷已暂存本机，网络恢复后将自动交卷。', '已到时', 'warning')
      startWaitForNetworkAndSubmit()
      return
    }

    response = await doRequest()

    if (response.status === 202 || response.data.record_status === 'awaiting_review') {
      awaitingReview.value = true
      draft.recordStatus = 'awaiting_review'
      startReviewPolling()
      alert(response.data.message || '答卷已保存，等待监考老师处理', '等待处理', 'warning')
      return
    }

    clearDraft()
    alert(`考试完成！得分: ${response.data.score}`, autoSubmit ? '时间到，已自动交卷' : '考试完成', 'success')
    router.push('/records')
  } catch (e) {
    if (e.response?.status === 409) {
      alert('该场考试已结束', '提示', 'warning')
      router.push('/records')
    } else if (e.code === 'ERR_NETWORK' || !navigator.onLine) {
      handleOffline()
      queueEvent('auto_submit', { meta: { offline: true, auto: autoSubmit } })
      alert('交卷时网络中断，答卷已暂存，恢复网络后会自动提交', '网络中断', 'warning')
      startWaitForNetworkAndSubmit()
    } else {
      alert(e.response?.data?.message || '提交失败，答案已本地暂存', '提交失败', 'error')
    }
  } finally {
    submitting.value = false
  }
}

function startWaitForNetworkAndSubmit() {
  if (waitNetTimer) clearInterval(waitNetTimer)
  waitNetTimer = setInterval(async () => {
    if (!navigator.onLine) return
    clearInterval(waitNetTimer)
    waitNetTimer = null
    const offlineSince = draft.offlineSince
    await handleReconnect(Math.round((Date.now() - (offlineSince || Date.now())) / 1000), offlineSince)
    if (!awaitingReview.value) {
      submitting.value = true
      try {
        const res = await api.post(`/exams/${paperId}/submit`, {
          exam_record_id: draft.exam_record_id,
          auto_submit: true,
          client_ts: Date.now(),
          device_id: deviceId,
          answers: answerEntries.value
        })
        if (res.status === 202 || res.data.record_status === 'awaiting_review') {
          awaitingReview.value = true
          startReviewPolling()
        } else {
          clearDraft()
          alert(`网络恢复，已自动交卷！得分: ${res.data.score}`, '自动交卷成功', 'success')
          router.push('/records')
        }
      } catch (e) {
        console.error('恢复后自动交卷失败', e)
      } finally {
        submitting.value = false
      }
    }
  }, 3000)
}
</script>
