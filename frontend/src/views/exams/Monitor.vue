<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center flex-wrap gap-3">
      <h1 class="text-2xl font-bold text-gray-900">断网续考监考</h1>
      <div class="flex items-center gap-2">
        <select v-model="filterStatus" @change="fetchRecords(1)" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
          <option value="">全部进行中</option>
          <option value="in_progress">考试进行中</option>
          <option value="pending_review">超时待审核</option>
        </select>
        <label class="flex items-center text-sm text-gray-600 gap-1">
          <input type="checkbox" v-model="onlyPending" @change="fetchRecords(1)"> 仅看待审核
        </label>
        <button @click="fetchRecords()" class="bg-indigo-600 text-white px-4 py-2 rounded text-sm hover:bg-indigo-700">刷新</button>
      </div>
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <div v-else-if="records.length === 0" class="text-center py-8 text-gray-500">暂无进行中的考试</div>

    <div v-else class="bg-white shadow rounded-lg overflow-hidden">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
            <th class="px-4 py-3">学生</th>
            <th class="px-4 py-3">试卷</th>
            <th class="px-4 py-3">开始时间</th>
            <th class="px-4 py-3">剩余/超时</th>
            <th class="px-4 py-3">断网</th>
            <th class="px-4 py-3">刷新/离开</th>
            <th class="px-4 py-3">换设备</th>
            <th class="px-4 py-3">状态</th>
            <th class="px-4 py-3 text-right">操作</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-100 text-sm">
          <tr v-for="r in records" :key="r.id" :class="{'bg-amber-50': r.time_check_status === 'pending_review'}">
            <td class="px-4 py-3 font-medium text-gray-900">{{ r.user?.real_name || r.user?.username }}</td>
            <td class="px-4 py-3 text-gray-700">{{ r.exam_paper?.title }}</td>
            <td class="px-4 py-3 text-gray-500">{{ formatDateTime(r.start_time) }}</td>
            <td class="px-4 py-3">
              <span v-if="r.overtime_seconds > 0" class="text-red-600 font-semibold">超时 {{ formatDuration(r.overtime_seconds) }}</span>
              <span v-else class="font-mono">{{ formatDuration(r.remaining_seconds) }}</span>
              <span v-if="r.extra_time_seconds > 0" class="text-xs text-green-600 block">已延时 {{ formatDuration(r.extra_time_seconds) }}</span>
            </td>
            <td class="px-4 py-3">
              <span :class="r.event_summary.offline_count > 0 ? 'text-amber-700' : 'text-gray-400'">
                {{ r.event_summary.offline_count }} 次
              </span>
              <span v-if="r.event_summary.total_offline_seconds > 0" class="text-xs text-gray-400 block">累计 {{ formatDuration(r.event_summary.total_offline_seconds) }}</span>
            </td>
            <td class="px-4 py-3" :class="r.event_summary.refresh_count > 3 ? 'text-amber-700' : 'text-gray-600'">
              {{ r.event_summary.refresh_count }} 次
            </td>
            <td class="px-4 py-3">
              <span :class="r.event_summary.device_switch_count >= 2 ? 'text-red-600 font-semibold' : (r.event_summary.device_switch_count === 1 ? 'text-amber-700' : 'text-gray-400')">
                {{ r.event_summary.device_switch_count }} 次
              </span>
              <span v-if="r.event_summary.has_danger" class="text-xs bg-red-100 text-red-700 px-1.5 py-0.5 rounded ml-1">高风险</span>
            </td>
            <td class="px-4 py-3">
              <span v-if="r.time_check_status === 'pending_review'" class="bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full text-xs font-medium">待审核</span>
              <span v-else-if="r.time_check_status === 'approved'" class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs">已延时</span>
              <span v-else class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs">进行中</span>
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <button @click="openDetail(r)" class="text-indigo-600 hover:text-indigo-800 mr-3">详情</button>
              <button v-if="r.time_check_status === 'pending_review' || r.overtime_seconds > 0"
                @click="openGrant(r)" class="text-green-600 hover:text-green-800 mr-3">延时</button>
              <button v-if="r.time_check_status === 'pending_review' || r.overtime_seconds > 0"
                @click="confirmForceSubmit(r)" class="text-red-600 hover:text-red-800">收卷</button>
            </td>
          </tr>
        </tbody>
      </table>

      <div class="px-4 py-3 flex items-center justify-between border-t text-sm text-gray-500">
        <span>共 {{ total }} 条</span>
        <div class="flex gap-2">
          <button :disabled="page <= 1" @click="fetchRecords(page - 1)" class="px-3 py-1 border rounded disabled:opacity-40">上一页</button>
          <span>{{ page }} / {{ lastPage }}</span>
          <button :disabled="page >= lastPage" @click="fetchRecords(page + 1)" class="px-3 py-1 border rounded disabled:opacity-40">下一页</button>
        </div>
      </div>
    </div>

    <!-- 详情抽屉 -->
    <div v-if="detail" class="fixed inset-0 z-50 flex justify-end" @click.self="detail = null">
      <div class="absolute inset-0 bg-black/40"></div>
      <div class="relative bg-white w-full max-w-2xl h-full overflow-y-auto shadow-xl">
        <div class="sticky top-0 bg-white border-b px-6 py-4 flex justify-between items-center">
          <h2 class="text-lg font-bold">考试事件详情</h2>
          <button @click="detail = null" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
        </div>
        <div class="px-6 py-4 space-y-5">
          <div class="bg-gray-50 rounded-lg p-4 text-sm space-y-1">
            <p><span class="text-gray-500">学生：</span>{{ detail.record.user?.real_name || detail.record.user?.username }}（{{ detail.record.user?.username }}）</p>
            <p><span class="text-gray-500">试卷：</span>{{ detail.record.exam_paper?.title }}</p>
            <p><span class="text-gray-500">开始：</span>{{ formatDateTime(detail.record.start_time) }}</p>
            <p><span class="text-gray-500">时间：</span>
              <span v-if="detail.overtime_seconds > 0" class="text-red-600">超时 {{ formatDuration(detail.overtime_seconds) }}</span>
              <span v-else>剩余 {{ formatDuration(detail.remaining_seconds) }}</span>
              <span v-if="detail.record.extra_time_seconds">，已延时 {{ formatDuration(detail.record.extra_time_seconds) }}</span>
            </p>
          </div>

          <div>
            <h3 class="font-semibold text-gray-800 mb-2">最新答案快照</h3>
            <div v-if="detail.latest_snapshot" class="text-xs space-y-1">
              <p class="text-gray-400">学生端时间：{{ formatDateTime(detail.latest_snapshot.client_time) }}</p>
              <div v-for="(ans, qid) in detail.latest_snapshot.answers" :key="qid"
                   class="flex gap-2 bg-gray-50 rounded px-3 py-2">
                <span class="text-gray-400 flex-shrink-0">第{{ questionIndex(qid) }}题</span>
                <span class="text-gray-700 break-all">{{ Array.isArray(ans) ? ans.join(',') : ans || '（空）' }}</span>
              </div>
              <p v-if="!detail.latest_snapshot.answers || Object.keys(detail.latest_snapshot.answers).length === 0" class="text-gray-400">暂无答案快照</p>
            </div>
            <p v-else class="text-gray-400 text-sm">暂无答案快照</p>
          </div>

          <div>
            <h3 class="font-semibold text-gray-800 mb-2">连接事件链</h3>
            <div class="relative pl-4 border-l-2 border-gray-200 space-y-3">
              <div v-for="ev in detail.events" :key="ev.id" class="relative">
                <span class="absolute -left-[22px] top-1 w-3 h-3 rounded-full border-2 border-white"
                  :class="{
                    'bg-gray-300': ev.risk_level === 'info',
                    'bg-amber-400': ev.risk_level === 'warning',
                    'bg-red-500': ev.risk_level === 'danger'
                  }"></span>
                <div class="text-sm">
                  <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-medium text-gray-800">{{ ev.event_label }}</span>
                    <span v-if="ev.reason === 'refresh'" class="text-xs bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded">刷新/离开</span>
                    <span v-else-if="ev.reason === 'offline'" class="text-xs bg-amber-50 text-amber-700 px-1.5 py-0.5 rounded">真实断网</span>
                    <span class="text-xs px-1.5 py-0.5 rounded"
                      :class="{
                        'bg-gray-100 text-gray-500': ev.risk_level === 'info',
                        'bg-amber-100 text-amber-700': ev.risk_level === 'warning',
                        'bg-red-100 text-red-700': ev.risk_level === 'danger'
                      }">{{ ev.risk_label }}</span>
                  </div>
                  <p class="text-xs text-gray-400 mt-0.5">
                    服务器 {{ ev.server_time }}
                    <template v-if="ev.client_time">｜学生端 {{ formatDateTime(ev.client_time) }}</template>
                    <template v-if="ev.offline_seconds">｜中断 {{ formatDuration(ev.offline_seconds) }}</template>
                  </p>
                  <p class="text-xs text-gray-400">{{ ev.device_label || '未知设备' }} · {{ ev.ip_address }}</p>
                </div>
              </div>
            </div>
          </div>

          <div v-if="detail.record.time_check_status === 'pending_review' || detail.overtime_seconds > 0" class="flex gap-3 pt-2 border-t">
            <button @click="openGrant(detail.record)" class="flex-1 bg-green-600 text-white py-2.5 rounded-lg hover:bg-green-700 font-medium">批准延时</button>
            <button @click="confirmForceSubmit(detail.record)" class="flex-1 bg-red-600 text-white py-2.5 rounded-lg hover:bg-red-700 font-medium">按时收卷评分</button>
          </div>
        </div>
      </div>
    </div>

    <!-- 延时弹窗 -->
    <div v-if="grantTarget" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/40" @click="grantTarget = null"></div>
      <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md p-6">
        <h3 class="text-lg font-bold mb-4">批准延长考试时间</h3>
        <div class="flex flex-wrap gap-2 mb-4">
          <button v-for="opt in [60, 180, 300, 600, 900]" :key="opt"
            @click="grantSeconds = opt"
            class="px-3 py-1.5 border rounded-lg text-sm"
            :class="grantSeconds === opt ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-gray-300 text-gray-600'">
            {{ opt >= 60 ? `${opt / 60} 分钟` : `${opt} 秒` }}
          </button>
        </div>
        <input type="number" min="1" v-model.number="grantSeconds" class="w-full border border-gray-300 rounded-lg px-3 py-2 mb-3" placeholder="自定义秒数">
        <textarea v-model="grantComment" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 mb-4 text-sm" placeholder="备注（可选），如：经核实为网络故障"></textarea>
        <div class="flex gap-3">
          <button @click="grantTarget = null" class="flex-1 py-2.5 border border-gray-300 rounded-lg text-gray-700">取消</button>
          <button @click="submitGrant" :disabled="grantSubmitting" class="flex-1 py-2.5 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50">确认延时</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'
import { useToast } from '../../composables/useToast'
import { useModal } from '../../composables/useModal'

const toast = useToast()
const { confirm } = useModal()

const records = ref([])
const loading = ref(false)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const filterStatus = ref('')
const onlyPending = ref(false)

const detail = ref(null)
const grantTarget = ref(null)
const grantSeconds = ref(180)
const grantComment = ref('')
const grantSubmitting = ref(false)

onMounted(() => fetchRecords(1))

async function fetchRecords(targetPage) {
  if (targetPage) page.value = targetPage
  loading.value = true
  try {
    const params = { page: page.value, per_page: 15 }
    if (filterStatus.value) params.status = filterStatus.value
    if (onlyPending.value) params.only_pending = 1
    const res = await api.get('/exams/monitor/records', { params })
    records.value = res.data.records.data
    lastPage.value = res.data.records.last_page
    total.value = res.data.records.total
  } catch (e) {
    toast.error('加载监考列表失败')
  } finally {
    loading.value = false
  }
}

async function openDetail(record) {
  try {
    const res = await api.get(`/exams/monitor/records/${record.id}`)
    detail.value = res.data
  } catch (e) {
    toast.error('加载详情失败')
  }
}

function openGrant(record) {
  grantTarget.value = record
  grantSeconds.value = 180
  grantComment.value = ''
}

async function submitGrant() {
  if (!grantSeconds.value || grantSeconds.value < 1) {
    toast.error('请填写有效的延时秒数')
    return
  }
  grantSubmitting.value = true
  try {
    const res = await api.post(`/exams/monitor/records/${grantTarget.value.id}/decision`, {
      action: 'grant_extra',
      granted_seconds: grantSeconds.value,
      comment: grantComment.value
    })
    toast.success(res.data.message || '延时已批准')
    grantTarget.value = null
    detail.value = null
    fetchRecords()
  } catch (e) {
    toast.error(e.response?.data?.message || '操作失败')
  } finally {
    grantSubmitting.value = false
  }
}

async function confirmForceSubmit(record) {
  const ok = await confirm(
    '将按服务器最近一次暂存的答案立即收卷并自动评分，学生不能再继续作答。确认收卷？',
    '确认按时收卷',
    'warning'
  )
  if (!ok) return
  try {
    const res = await api.post(`/exams/monitor/records/${record.id}/decision`, {
      action: 'force_submit',
      comment: '监考老师按时收卷'
    })
    toast.success(res.data.message || '已收卷')
    detail.value = null
    fetchRecords()
  } catch (e) {
    toast.error(e.response?.data?.message || '操作失败')
  }
}

// 详情里快照按试卷题目顺序映射题号
function questionIndex(qid) {
  const idx = (detail.value?.questions || []).findIndex(q => String(q.id) === String(qid))
  return idx >= 0 ? idx + 1 : qid
}

function pad(n) { return String(n).padStart(2, '0') }

function formatDuration(seconds) {
  seconds = Math.max(0, Math.floor(seconds || 0))
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  if (h > 0) return `${h}时${pad(m)}分`
  if (m > 0) return s ? `${m}分${pad(s)}秒` : `${m}分钟`
  return `${s}秒`
}

function formatDateTime(t) {
  if (!t) return '-'
  const d = new Date(t)
  if (isNaN(d.getTime())) return t
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}
</script>
