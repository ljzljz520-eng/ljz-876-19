<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center flex-wrap gap-3">
      <h1 class="text-2xl font-bold text-gray-900">{{ examPaper?.title }}</h1>
      <div class="flex items-center gap-4">
        <span v-if="timeCheckStatus === 'pending_review'"
          class="text-sm bg-amber-100 text-amber-800 px-3 py-1 rounded-full font-medium">
          已超时，等待监考老师处理
        </span>
        <div class="text-lg">
          剩余时间:
          <span class="font-mono font-bold" :class="{'text-red-600': timeRemaining < 60 && !expired}">
            {{ expired ? '00:00' : formatTime(timeRemaining) }}
          </span>
        </div>
      </div>
    </div>

    <!-- 断网提示条 -->
    <div v-if="locked" class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4">
      <div class="font-semibold">{{ lockTitle }}</div>
      <p class="text-sm mt-1">{{ lockMessage }}</p>
      <button @click="goRecords" class="mt-3 bg-red-600 text-white text-sm px-4 py-2 rounded hover:bg-red-700">返回成绩列表</button>
    </div>
    <div v-else-if="!online" class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 flex items-start gap-3">
      <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m-12.728 0a9 9 0 010-12.728m9.9 9.9a4.5 4.5 0 000-6.364m-7.072 6.364a4.5 4.5 0 010-6.364M12 12h.01" />
      </svg>
      <div class="flex-1">
        <div class="font-semibold">网络已断开，答案已自动暂存在本机</div>
        <p class="text-sm mt-1">请不要关闭页面，继续作答；网络恢复后会自动上报并继续考试。已暂存 {{ savedAnswerCount }} 题答案（本地时间 {{ savedAtText }}）。</p>
      </div>
    </div>
    <div v-else-if="resumeNotice" class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 text-sm">
      {{ resumeNotice }}
    </div>

    <!-- 题目状态导航 -->
    <div v-if="!loading && questions.length > 0 && !locked" class="bg-white rounded-lg shadow p-4">
      <div class="text-sm font-medium text-gray-700 mb-3">题目状态（{{ answeredCount }}/{{ questions.length }} 已答{{ offlineTotalSeconds > 0 ? `，累计断网 ${formatDuration(offlineTotalSeconds)}` : '' }}）</div>
      <div class="flex flex-wrap gap-2">
        <button v-for="(question, index) in questions" :key="question.id"
          @click="scrollToQuestion(question.id)"
          class="w-9 h-9 rounded-md text-sm font-medium border transition-colors"
          :class="statusBadgeClass(question.id, index)">
          {{ index + 1 }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
      <p class="text-sm text-gray-500 mt-2">正在恢复考试数据…</p>
    </div>
    <div v-else-if="!loading && questions.length > 0 && !locked" class="space-y-8">
      <div v-for="(question, index) in questions" :key="question.id" :id="`question-${question.id}`" class="bg-white rounded-lg shadow p-6 scroll-mt-24">
        <div class="flex items-start mb-4">
          <span class="bg-indigo-100 text-indigo-800 text-sm font-medium px-2.5 py-0.5 rounded mr-3">{{ index + 1 }}</span>
          <div class="flex-1">
            <div class="flex items-center justify-between mb-2">
              <h3 class="text-lg font-medium text-gray-900">{{ question.title }}</h3>
              <button @click="toggleMark(question.id)"
                class="text-xs px-2 py-1 rounded-full border ml-3 flex-shrink-0"
                :class="marks[question.id]
                  ? 'bg-amber-100 text-amber-700 border-amber-300'
                  : 'bg-gray-50 text-gray-500 border-gray-200 hover:bg-gray-100'">
                {{ marks[question.id] ? '★ 已标记' : '☆ 标记复查' }}
              </button>
            </div>
            <p class="text-sm text-gray-500 mb-3">分值: {{ question.score }}分 | 题型: {{ questionTypeLabel(question.type) }}</p>
            <div class="space-y-2">
              <!-- 单选题 -->
              <template v-if="question.type === 'single_choice'">
                <label v-for="(label, key) in question.options" :key="key" class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50" :class="{'border-indigo-500 bg-indigo-50': answers[question.id] === key}">
                  <input type="radio" :name="'question_' + question.id" :value="key" v-model="answers[question.id]" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                  <span class="ml-3">{{ key }}. {{ label }}</span>
                </label>
              </template>
              <!-- 判断题 -->
              <template v-else-if="question.type === 'true_false'">
                <label class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50" :class="{'border-indigo-500 bg-indigo-50': answers[question.id] === 'true'}">
                  <input type="radio" :name="'question_' + question.id" value="true" v-model="answers[question.id]" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                  <span class="ml-3">正确</span>
                </label>
                <label class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50" :class="{'border-indigo-500 bg-indigo-50': answers[question.id] === 'false'}">
                  <input type="radio" :name="'question_' + question.id" value="false" v-model="answers[question.id]" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                  <span class="ml-3">错误</span>
                </label>
              </template>
              <!-- 多选题 -->
              <template v-else-if="question.type === 'multiple_choice'">
                <label v-for="(label, key) in question.options" :key="key" class="flex items-center p-3 border rounded cursor-pointer hover:bg-gray-50" :class="{'border-indigo-500 bg-indigo-50': (answers[question.id] || []).includes(key)}">
                  <input type="checkbox" :value="key" @change="toggleMultipleChoice(question.id, key)" :checked="(answers[question.id] || []).includes(key)" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                  <span class="ml-3">{{ key }}. {{ label }}</span>
                </label>
              </template>
              <!-- 填空题/问答题 -->
              <template v-else>
                <textarea v-model="answers[question.id]" rows="3" class="w-full border border-gray-300 rounded-md p-3 focus:ring-indigo-500 focus:border-indigo-500" placeholder="请输入答案"></textarea>
              </template>
            </div>
          </div>
        </div>
      </div>
      <div class="flex justify-between">
        <router-link to="/exams" class="bg-gray-300 text-gray-700 py-2 px-4 rounded hover:bg-gray-400">返回</router-link>
        <button @click="submitExam(false)" :disabled="submitting || expired" class="bg-indigo-600 text-white py-2 px-6 rounded hover:bg-indigo-700 disabled:opacity-50">
          {{ submitting ? '提交中...' : (expired ? '已到时间' : '提交答卷') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../api'
import { useModal } from '../../composables/useModal'
import { useToast } from '../../composables/useToast'
import { useExamDraft } from '../../composables/useExamDraft'

const HEARTBEAT_INTERVAL_MS = 10000

const route = useRoute()
const router = useRouter()
const { alert } = useModal()
const toast = useToast()
const paperId = route.params.id
const { deviceId, deviceLabel, load: loadDraft, save: saveDraft, clear: clearDraft } = useExamDraft(paperId)

// 各题最后编辑时间（从本地暂存恢复，用于和服务器快照比较新旧）
const answerTimes = (() => {
  try {
    return loadDraft()?.answer_times || {}
  } catch (e) {
    return {}
  }
})()

const examPaper = ref(null)
const examRecord = ref(null)
const questions = ref([])
const answers = ref({})
const marks = ref({})
const loading = ref(true)
const submitting = ref(false)
const timeRemaining = ref(0)
const expired = ref(false)
const online = ref(navigator.onLine)
const resumeNotice = ref('')
const offlineTotalSeconds = ref(0)

// 锁定状态：换设备冲突 / 考试已被收卷
const locked = ref(false)
const lockTitle = ref('')
const lockMessage = ref('')

let timer = null
let heartbeatTimer = null
let beat = 0
let clockOffsetMs = 0 // 本地时钟与服务器偏差
let offlineAt = null

const answeredCount = computed(() =>
  questions.value.filter(q => isAnswered(answers.value[q.id])).length
)
const savedAnswerCount = computed(() =>
  Object.values(answers.value).filter(v => isAnswered(v)).length
)
const lastSavedAt = ref('')
const savedAtText = computed(() => lastSavedAt.value || new Date().toLocaleTimeString())
const timeCheckStatus = computed(() => examRecord.value?.time_check_status || 'normal')

function isAnswered(v) {
  if (Array.isArray(v)) return v.length > 0
  return v !== undefined && v !== null && String(v).trim() !== ''
}

onMounted(async () => {
  try {
    const localDraft = loadDraft()
    let resumed = false

    // 先尝试“开始”；若已有进行中的考试，后端返回 resume=true，再走续考接口
    try {
      const startRes = await api.post(`/exams/${paperId}/start`, {
        device_id: deviceId,
        device_label: deviceLabel
      }, { skipErrorMessage: true })
      if (!startRes.data.resume) {
        // 全新开考：清掉可能残留的旧草稿，避免脏合并
        clearDraft()
        await applyBootstrap(startRes.data, null)
      } else {
        resumed = true
      }
    } catch (e) {
      if (e.response && e.response.status !== 409) {
        // 有服务器响应但不是“可恢复”的情况，交给下面续考接口/外层处理
      }
      // 网络错误（离线）或已有记录 → 继续走续考接口
    }

    if (!examPaper.value) {
      const res = await api.get(`/exams/${paperId}/questions`, { skipErrorMessage: true })
      await applyBootstrap(res.data, localDraft)
      resumed = true
    } else if (localDraft && localDraft.exam_record_id === examRecord.value.id) {
      mergeLocalDraft(localDraft)
    }

    if (resumed) {
      // 恢复（刷新 / 断网回来 / 换设备）：通知后端，拿到离线时长与服务器剩余时间
      try {
        await sendPing('resume')
      } catch (e) { /* 心跳失败不阻塞答题 */ }
    }

    persistDraft()
    startTimer()
    startHeartbeat()

    window.addEventListener('online', handleOnline)
    window.addEventListener('offline', handleOffline)
    window.addEventListener('pagehide', handlePageHide)
    document.addEventListener('visibilitychange', handleVisibilityChange)
  } catch (e) {
    if (e.response?.status === 404) {
      alert('未找到进行中的考试，请从考试列表进入。', '考试加载失败', 'error')
      router.push('/exams')
    } else if (!e.response) {
      // 初次进入就断网：使用本地草稿离线作答
      const localDraft = loadDraft()
      if (localDraft?.questions?.length) {
        applyOfflineDraft(localDraft)
        startTimer()
        window.addEventListener('online', handleOnline)
        window.addEventListener('offline', handleOffline)
        window.addEventListener('pagehide', handlePageHide)
        toast.warning('当前离线，正在使用本地暂存继续考试')
      } else {
        alert('网络不可用且本地没有该场考试的暂存数据，请恢复网络后重试。', '无法开始考试', 'error')
        router.push('/exams')
      }
    } else {
      const msg = e.response?.data?.message || '获取考试信息失败'
      alert(msg, '考试加载失败', 'error')
      router.push('/exams')
    }
  } finally {
    loading.value = false
  }
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
  if (heartbeatTimer) clearInterval(heartbeatTimer)
  if (probeTimer) clearInterval(probeTimer)
  window.removeEventListener('online', handleOnline)
  window.removeEventListener('offline', handleOffline)
  window.removeEventListener('pagehide', handlePageHide)
  document.removeEventListener('visibilitychange', handleVisibilityChange)
})

function applyBootstrap(data, localDraft) {
  examPaper.value = data.exam_paper
  examRecord.value = data.exam_record
  questions.value = data.questions || []
  syncClock(data.server_time)
  const remaining = data.remaining_seconds ?? examPaper.value.total_time * 60
  timeRemaining.value = remaining
  expired.value = !!data.expired

  if (data.time_check_status === 'pending_review') {
    expired.value = true
    // 重新进入一场已超时、待监考处理的考试：开始轮询审核结果
    setTimeout(pollDecision, 15000)
  }

  // 合并服务器快照（心跳保存的答案，刷新/换设备后恢复的关键）
  // 规则：以题目为单位，谁的最后编辑时间新就用谁
  const snapshot = data.latest_snapshot
  const snapshotTs = snapshot?.client_time ? new Date(snapshot.client_time).getTime() : 0
  if (snapshot?.answers) {
    Object.entries(snapshot.answers).forEach(([qid, ans]) => {
      if (isAnswered(ans)) answers.value[qid] = ans
    })
  }
  if (snapshot?.marks) {
    Object.entries(snapshot.marks).forEach(([qid, m]) => {
      marks.value[qid] = !!m
    })
  }

  if (localDraft) {
    mergeLocalDraft(localDraft, snapshotTs)
  }

  if (expired.value && data.time_check_status !== 'pending_review') {
    // 极端情况：恢复时已超时，自动提交由后续流程处理
    submitExam(true)
  }
}

function applyOfflineDraft(draft) {
  examPaper.value = { id: paperId, title: draft.title, total_time: draft.total_time }
  examRecord.value = { id: draft.exam_record_id, time_check_status: 'normal' }
  questions.value = draft.questions || []
  answers.value = draft.answers || {}
  marks.value = draft.marks || {}
  Object.assign(answerTimes, draft.answer_times || {})
  // 离线时用本地记录的截止时刻估算
  if (draft.deadline_local) {
    const left = Math.max(0, Math.round((new Date(draft.deadline_local) - Date.now()) / 1000))
    timeRemaining.value = left
    expired.value = left <= 0
  } else {
    timeRemaining.value = draft.remaining_seconds ?? 0
  }
}

/**
 * 合并本地暂存与服务器快照。
 * 同一题：本地最后编辑时间 > 服务器快照时间 → 用本地；否则保留服务器快照。
 * 服务器上没有的题、或快照为空 → 直接用本地。
 */
function mergeLocalDraft(draft, snapshotTs = 0) {
  if (draft.answers) {
    Object.entries(draft.answers).forEach(([qid, ans]) => {
      if (!isAnswered(ans)) return
      const localT = draft.answer_times?.[qid] || 0
      const serverAns = answers.value[qid]
      if (!isAnswered(serverAns) || localT > snapshotTs) {
        answers.value[qid] = ans
      }
    })
  }
  if (draft.marks) {
    Object.entries(draft.marks).forEach(([qid, m]) => { marks.value[qid] = !!m })
  }
}

function persistDraft() {
  if (!examRecord.value?.id) return
  const deadlineLocal = new Date(Date.now() + timeRemaining.value * 1000).toISOString()
  saveDraft({
    exam_record_id: examRecord.value.id,
    title: examPaper.value?.title,
    total_time: examPaper.value?.total_time,
    questions: questions.value.map(q => ({ id: q.id, type: q.type, title: q.title, options: q.options, score: q.score })),
    answers: answers.value,
    marks: marks.value,
    answer_times: answerTimes,
    remaining_seconds: timeRemaining.value,
    deadline_local: deadlineLocal
  })
  lastSavedAt.value = new Date().toLocaleTimeString()
}

function markAnsweredTime(questionId) {
  answerTimes[questionId] = Date.now()
  persistDraft()
}

// ===== 服务器权威计时 =====
function syncClock(serverTime) {
  if (serverTime) {
    clockOffsetMs = new Date(serverTime).getTime() - Date.now()
  }
}

function startTimer() {
  if (timer) clearInterval(timer)
  timer = setInterval(() => {
    if (timeRemaining.value > 0) {
      timeRemaining.value--
      if (timeRemaining.value % 5 === 0) persistDraft()
    } else if (!expired.value) {
      expired.value = true
      clearInterval(timer)
      submitExam(true)
    }
  }, 1000)
}

// ===== 心跳与断网/恢复 =====
let probeTimer = null
function startHeartbeat() {
  if (heartbeatTimer) clearInterval(heartbeatTimer)
  beat = 0
  heartbeatTimer = setInterval(() => {
    if (navigator.onLine && !locked.value) {
      sendPing('heartbeat')
    }
  }, HEARTBEAT_INTERVAL_MS)

  // 浏览器 online/offline 事件可能漏报：定时用心跳主动探测，一旦成功就触发恢复
  if (probeTimer) clearInterval(probeTimer)
  probeTimer = setInterval(() => {
    if (!online.value && navigator.onLine && !locked.value) {
      handleOnline()
    }
  }, 5000)
}

async function sendPing(event) {
  beat++
  try {
    const res = await api.post(`/exams/${paperId}/ping`, {
      exam_record_id: examRecord.value.id,
      event,
      device_id: deviceId,
      device_label: deviceLabel,
      client_time: new Date().toISOString(),
      answers: answers.value,
      marks: marks.value,
      beat
    }, { skipErrorMessage: true })

    if (res.data.server_time) syncClock(res.data.server_time)
    if (res.data.time_check_status === 'pending_review') {
      examRecord.value.time_check_status = 'pending_review'
      expired.value = true
    } else if (res.data.status === 'in_progress' && res.data.remaining_seconds !== undefined) {
      // 以服务器剩余时间为准纠偏本地倒计时（偏差 >2 秒才调整，避免跳秒）
      const serverRemaining = res.data.remaining_seconds
      if (Math.abs(serverRemaining - timeRemaining.value) > 2) {
        timeRemaining.value = serverRemaining
      }
      expired.value = res.data.expired
      if (res.data.time_check_status === 'approved') {
        examRecord.value.time_check_status = 'approved'
      }
    }

    if (event === 'resume') {
      offlineTotalSeconds.value += res.data.offline_seconds || 0
      if (res.data.switched_device) {
        resumeNotice.value = `检测到你更换了设备，已恢复上次答案（断合约 ${res.data.offline_seconds || 0} 秒，该情况已记录，不会自动判定为作弊）。`
      } else if (res.data.offline_seconds > 0) {
        resumeNotice.value = `网络已恢复，已自动同步暂存答案，本次断网 ${formatDuration(res.data.offline_seconds)}。`
      } else {
        resumeNotice.value = '已恢复考试，本地暂存答案已同步。'
      }
      setTimeout(() => { resumeNotice.value = '' }, 8000)
      persistDraft()
    }

    if (res.data.expired && res.data.time_check_status !== 'pending_review' && !submitting.value) {
      expired.value = true
      submitExam(true)
    }
  } catch (e) {
    if (e.response?.status === 409 && e.response.data?.device_conflict) {
      handleDeviceConflict(e.response.data)
    } else if (e.response?.status === 409 && e.response.data?.closed) {
      // 考试已被监考收卷
      locked.value = true
      lockTitle.value = '该场考试已结束'
      lockMessage.value = '监考老师已按时收卷。'
      if (timer) clearInterval(timer)
      if (heartbeatTimer) clearInterval(heartbeatTimer)
    } else if (!e.response) {
      // 请求发不出去：主动判定离线（不依赖浏览器 offline 事件）
      if (online.value) {
        online.value = false
        offlineAt = offlineAt || Date.now()
      }
    }
  }
}

function handleOffline() {
  online.value = false
  offlineAt = Date.now()
  toast.warning('网络已断开，答案暂存本机，恢复后将自动继续')
  // 尽力上报断网（断网时大概率失败，恢复时的 gap 会兜底识别）
  api.post(`/exams/${paperId}/ping`, {
    exam_record_id: examRecord.value?.id,
    event: 'network_lost',
    device_id: deviceId,
    device_label: deviceLabel,
    client_time: new Date().toISOString(),
    answers: answers.value,
    marks: marks.value
  }, { skipErrorMessage: true }).catch(() => {})
  persistDraft()
}

async function handleOnline() {
  if (online.value) return
  online.value = true
  if (offlineAt) {
    offlineTotalSeconds.value += Math.round((Date.now() - offlineAt) / 1000)
    offlineAt = null
  }
  if (examRecord.value?.id && !locked.value) {
    await sendPing('resume')
  }
}

function handleVisibilityChange() {
  // 从后台切回前台时立即续考同步（移动端常见场景）
  if (!document.hidden && navigator.onLine && examRecord.value?.id) {
    sendPing('resume')
  }
}

function handlePageHide() {
  if (!examRecord.value?.id) return
  persistDraft()
  // 刷新/关闭页面：fetch keepalive 上报 page_leave，与真实断网区分
  const token = localStorage.getItem('token')
  try {
    fetch(import.meta.env.VITE_API_BASE_URL
      ? `${import.meta.env.VITE_API_BASE_URL}/exams/${paperId}/leave-beacon`
      : `/api/exams/${paperId}/leave-beacon`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      keepalive: true,
      body: JSON.stringify({
        token,
        exam_record_id: examRecord.value.id,
        device_id: deviceId,
        device_label: deviceLabel,
        client_time: new Date().toISOString(),
        answers: answers.value,
        marks: marks.value
      })
    }).catch(() => {})
  } catch (e) { /* 页面卸载阶段忽略 */ }
}

function handleDeviceConflict(data) {
  locked.value = true
  lockTitle.value = '本场考试已在其他设备继续'
  lockMessage.value = `检测到活跃设备：${data.active_device_label || '另一台设备'}。为防止重复作答，本设备已锁定，请在原设备上继续考试。`
  if (timer) clearInterval(timer)
  if (heartbeatTimer) clearInterval(heartbeatTimer)
  if (probeTimer) clearInterval(probeTimer)
}

function goRecords() {
  router.push('/records')
}

// ===== 答题交互 =====
const toggleMultipleChoice = (questionId, key) => {
  if (!answers.value[questionId]) answers.value[questionId] = []
  const idx = answers.value[questionId].indexOf(key)
  if (idx === -1) answers.value[questionId].push(key)
  else answers.value[questionId].splice(idx, 1)
  markAnsweredTime(questionId)
}

function toggleMark(questionId) {
  marks.value[questionId] = !marks.value[questionId]
  persistDraft()
}

function statusBadgeClass(questionId, index) {
  if (marks.value[questionId]) return 'bg-amber-100 text-amber-700 border-amber-300'
  if (isAnswered(answers.value[questionId])) return 'bg-indigo-600 text-white border-indigo-600'
  return 'bg-white text-gray-600 border-gray-300'
}

function scrollToQuestion(questionId) {
  document.getElementById(`question-${questionId}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// 监听所有答案变化，统一暂存
watch(answers, () => {
  Object.keys(answers.value).forEach(qid => {
    if (isAnswered(answers.value[qid]) && !answerTimes[qid]) answerTimes[qid] = Date.now()
  })
  persistDraft()
}, { deep: true })

// ===== 提交 =====
const submitExam = async (auto = false) => {
  if (submitting.value || locked.value) return
  submitting.value = true
  persistDraft()

  const answerData = Object.entries(answers.value)
    .filter(([, ans]) => isAnswered(ans))
    .map(([questionId, answer]) => ({
      question_id: parseInt(questionId),
      answer: Array.isArray(answer) ? answer.join(',') : answer
    }))

  const doSubmit = async () => {
    const res = await api.post(`/exams/${paperId}/submit`, {
      exam_record_id: examRecord.value.id,
      answers: answerData,
      device_id: deviceId,
      client_time: new Date().toISOString()
    })
    clearDraft()
    alert(`考试完成！得分: ${res.data.score}`, '考试完成', 'success')
    router.push('/records')
  }

  try {
    if (online.value) {
      await doSubmit()
    } else {
      // 离线到点：答案留在本地，恢复后由重连流程自动补提
      toast.warning('当前网络不可用，答案已暂存，网络恢复后将自动提交')
      setTimeout(() => {
        const retry = async () => {
          if (navigator.onLine) {
            try { await doSubmit() } catch (e) { handleSubmitError(e, auto) }
          } else {
            setTimeout(retry, 5000)
          }
        }
        retry()
      }, 3000)
    }
  } catch (e) {
    handleSubmitError(e, auto)
  } finally {
    submitting.value = false
  }
}

function handleSubmitError(e, auto) {
  const data = e.response?.data
  if (e.response?.status === 202 || data?.pending_review) {
    examRecord.value.time_check_status = 'pending_review'
    expired.value = true
    alert(data.message || '已超过考试时长，答案已保存，等待监考老师决定是否延时。', '等待监考处理', 'warning')
    setTimeout(pollDecision, 15000)
    return
  }
  if (e.response?.status === 409 && data?.closed) {
    clearDraft()
    alert('该场考试已结束', '提示', 'info')
    router.push('/records')
    return
  }
  if (!e.response) {
    toast.error('网络不可用，答案已暂存，恢复后自动提交')
    return
  }
  alert(data?.message || '提交失败', '提交失败', 'error')
}

async function pollDecision() {
  if (locked.value) return
  try {
    const res = await api.get(`/exams/${paperId}/questions`, { skipErrorMessage: true })
    const rec = res.data.exam_record
    if (rec.status === 'graded' || rec.status === 'submitted') {
      clearDraft()
      alert('监考老师已按时收卷，考试结束。', '考试结束', 'info')
      router.push('/records')
      return
    }
    if (rec.time_check_status === 'approved') {
      examRecord.value.time_check_status = 'approved'
      examRecord.value.extra_time_seconds = rec.extra_time_seconds
      timeRemaining.value = res.data.remaining_seconds
      expired.value = false
      startTimer()
      startHeartbeat()
      toast.success(`监考老师已批准延时，可继续作答 ${Math.round(res.data.remaining_seconds / 60)} 分钟`)
      return
    }
    // 仍在 pending_review，继续轮询
    if (rec.time_check_status === 'pending_review') {
      setTimeout(pollDecision, 15000)
    }
  } catch (e) {
    if (e.response?.status === 404) {
      clearDraft()
      router.push('/records')
      return
    }
    setTimeout(pollDecision, 30000)
  }
}

const formatTime = (seconds) => {
  const mins = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
}
const formatDuration = (seconds) => {
  if (seconds < 60) return `${seconds}秒`
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return s ? `${m}分${s}秒` : `${m}分钟`
}

const questionTypeLabel = (type) => ({
  single_choice: '单选题',
  multiple_choice: '多选题',
  true_false: '判断题',
  fill_blank: '填空题',
  essay: '问答题'
}[type] || type)
</script>
