<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">监考中心 · 断网续考处理</h1>
      <div class="flex items-center gap-2">
        <select v-model="filterStatus" @change="fetchRecords" class="border border-gray-300 rounded-md text-sm px-3 py-2">
          <option value="">全部进行中</option>
          <option value="awaiting_review">仅待处理</option>
          <option value="in_progress">仅考试中</option>
        </select>
        <button @click="fetchRecords" class="bg-indigo-600 text-white text-sm py-2 px-4 rounded hover:bg-indigo-700">刷新</button>
      </div>
    </div>

    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700 leading-relaxed">
      系统会自动甄别三种情况并标注：<b>真实断网</b>（断网/恢复事件成对且与心跳缺口吻合）、
      <b>刷新页面</b>（设备指纹不变、短时心跳缺失）、<b>换设备登录</b>（设备指纹变化）。
      超过允许时长（含宽限期）的考试进入“待处理”，由老师决定延时或收卷，系统不自动判作弊。
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <div v-else-if="records.length === 0" class="text-center py-8 text-gray-500">暂无进行中的考试</div>

    <div v-else class="bg-white shadow overflow-x-auto rounded-lg">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">考生</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">试卷</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">状态</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">系统甄别</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">断网/刷新/换设备</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">已答</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">最近心跳</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <tr v-for="r in records" :key="r.id" :class="{'bg-yellow-50': r.status === 'awaiting_review'}">
            <td class="px-4 py-3 whitespace-nowrap">
              <div class="font-medium text-gray-900">{{ r.user?.real_name || r.user?.username }}</div>
              <div class="text-xs text-gray-400">{{ r.user?.username }}</div>
            </td>
            <td class="px-4 py-3 whitespace-nowrap">{{ r.exam_paper?.title }}</td>
            <td class="px-4 py-3 whitespace-nowrap">
              <span v-if="r.status === 'awaiting_review'" class="px-2 py-0.5 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800">
                待处理{{ r.review_reason === 'device_switched' ? '·换设备' : '·超时' }}
              </span>
              <span v-else-if="r.is_overdue" class="px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">已超时</span>
              <span v-else class="px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800">考试中</span>
            </td>
            <td class="px-4 py-3 whitespace-nowrap">
              <span :class="classifyClass(r.classification)" class="px-2 py-0.5 rounded-full text-xs font-semibold">
                {{ classifyLabel(r.classification) }}
              </span>
            </td>
            <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-600">
              <div>断网 {{ r.event_summary.offline }} 次 / 共 {{ r.event_summary.offline_duration }} 秒</div>
              <div>刷新 {{ r.event_summary.page_refresh }} 次 · 切屏 {{ r.event_summary.page_hidden }} 次</div>
              <div>换设备 {{ r.event_summary.device_switch }} 次</div>
            </td>
            <td class="px-4 py-3 whitespace-nowrap">{{ r.answered_count }}</td>
            <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
              {{ r.last_heartbeat_at ? new Date(r.last_heartbeat_at).toLocaleTimeString() : '-' }}
            </td>
            <td class="px-4 py-3 whitespace-nowrap space-x-2">
              <button class="text-indigo-600 hover:underline" @click="openDetail(r)">详情</button>
              <button class="text-green-600 hover:underline" @click="openExtend(r)">延时</button>
              <button class="text-red-600 hover:underline" @click="openTerminate(r)">收卷</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 详情弹窗：事件时间线 -->
    <div v-if="detail" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="detail = null">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[85vh] flex flex-col">
        <div class="px-6 py-4 border-b flex justify-between items-center">
          <h2 class="text-lg font-bold">考试过程详情 #{{ detail.record.id }}</h2>
          <button @click="detail = null" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <div class="p-6 overflow-y-auto space-y-4 text-sm">
          <div class="grid grid-cols-2 gap-2 text-gray-600">
            <div>考生：<b>{{ detail.record.user?.real_name || detail.record.user?.username }}</b></div>
            <div>试卷：<b>{{ detail.record.exam_paper?.title }}</b></div>
            <div>状态：<b>{{ statusLabel(detail.record.status) }}</b></div>
            <div>累计离线：<b>{{ detail.record.offline_total }} 秒</b></div>
            <div>开始：{{ fmt(detail.record.start_time) }}</div>
            <div>截止：{{ fmt(detail.record.deadline_at) }}</div>
            <div v-if="detail.record.review_remark" class="col-span-2">处理备注：{{ detail.record.review_remark }}</div>
          </div>

          <div>
            <span class="font-semibold">系统甄别：</span>
            <span :class="classifyClass(detail.classification)" class="px-2 py-0.5 rounded-full text-xs font-semibold">
              {{ classifyLabel(detail.classification) }}
            </span>
          </div>

          <h3 class="font-semibold pt-2">事件时间线</h3>
          <div class="border-l-2 border-gray-200 pl-4 space-y-3">
            <div v-for="ev in detail.events" :key="ev.id" class="relative">
              <span class="absolute -left-[21px] top-1 w-2.5 h-2.5 rounded-full" :class="eventDot(ev.type)"></span>
              <div class="flex justify-between gap-2">
                <span class="font-medium">{{ ev.type_label }}</span>
                <span class="text-xs text-gray-400">{{ new Date(ev.server_ts).toLocaleString() }}</span>
              </div>
              <div class="text-xs text-gray-500">
                <span v-if="ev.duration">持续 {{ ev.duration }} 秒 · </span>
                <span v-if="!ev.is_online" class="text-amber-600">离线补报 · </span>
                设备 {{ ev.device_id ? ev.device_id.slice(0, 14) : '—' }}
              </div>
              <pre v-if="ev.meta" class="text-[11px] bg-gray-50 rounded p-1 mt-1 whitespace-pre-wrap break-all">{{ JSON.stringify(ev.meta) }}</pre>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 延时弹窗 -->
    <div v-if="extendTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="extendTarget = null">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 space-y-4">
        <h2 class="text-lg font-bold">批准延时</h2>
        <p class="text-sm text-gray-600">为「{{ extendTarget.user?.real_name || extendTarget.user?.username }}」的《{{ extendTarget.exam_paper?.title }}》延长作答时间。</p>
        <label class="block text-sm">延长分钟数
          <input v-model.number="extendMinutes" type="number" min="1" max="300" class="mt-1 w-full border rounded-md px-3 py-2">
        </label>
        <label class="block text-sm">备注（可选）
          <input v-model="extendRemark" type="text" class="mt-1 w-full border rounded-md px-3 py-2" placeholder="如：经核实为网络故障，同意延时">
        </label>
        <div class="flex justify-end gap-2 pt-2">
          <button class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300" @click="extendTarget = null">取消</button>
          <button class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700" @click="confirmExtend">确认延时</button>
        </div>
      </div>
    </div>

    <!-- 收卷确认 -->
    <div v-if="terminateTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="terminateTarget = null">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 space-y-4">
        <h2 class="text-lg font-bold text-red-600">终止并收卷</h2>
        <p class="text-sm text-gray-600">将立即结束「{{ terminateTarget.user?.real_name || terminateTarget.user?.username }}」的考试，并按当前已保存的答卷判分。此操作不可撤销。</p>
        <label class="block text-sm">处理备注（可选）
          <input v-model="terminateRemark" type="text" class="mt-1 w-full border rounded-md px-3 py-2" placeholder="如：超时且无断网记录">
        </label>
        <div class="flex justify-end gap-2 pt-2">
          <button class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300" @click="terminateTarget = null">取消</button>
          <button class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700" @click="confirmTerminate">确认收卷</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'

const records = ref([])
const loading = ref(true)
const filterStatus = ref('')
const detail = ref(null)
const extendTarget = ref(null)
const extendMinutes = ref(5)
const extendRemark = ref('')
const terminateTarget = ref(null)
const terminateRemark = ref('')

onMounted(fetchRecords)

async function fetchRecords() {
  loading.value = true
  try {
    const params = {}
    if (filterStatus.value) params.status = filterStatus.value
    const res = await api.get('/monitoring/records', { params })
    records.value = res.data.records.data
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

async function openDetail(r) {
  const res = await api.get(`/monitoring/records/${r.id}`)
  detail.value = res.data
}

function openExtend(r) {
  extendTarget.value = r
  extendMinutes.value = 5
  extendRemark.value = ''
}

async function confirmExtend() {
  try {
    const res = await api.post(`/monitoring/records/${extendTarget.value.id}/extend`, {
      extra_minutes: extendMinutes.value,
      remark: extendRemark.value
    })
    alert(res.data.message)
    extendTarget.value = null
    await fetchRecords()
  } catch (e) {
    alert(e.response?.data?.message || '操作失败')
  }
}

function openTerminate(r) {
  terminateTarget.value = r
  terminateRemark.value = ''
}

async function confirmTerminate() {
  if (!window.confirm('确定要终止考试并立即判分吗？')) return
  try {
    const res = await api.post(`/monitoring/records/${terminateTarget.value.id}/terminate`, {
      remark: terminateRemark.value
    })
    alert(`${res.data.message}，得分：${res.data.score}`)
    terminateTarget.value = null
    await fetchRecords()
  } catch (e) {
    alert(e.response?.data?.message || '操作失败')
  }
}

function classifyLabel(c) {
  return {
    network_outage: '真实断网',
    page_refresh: '刷新页面',
    device_switch: '换设备登录',
    suspicious: '异常待核实',
    normal: '正常'
  }[c] || c
}

function classifyClass(c) {
  return {
    network_outage: 'bg-amber-100 text-amber-800',
    page_refresh: 'bg-blue-100 text-blue-800',
    device_switch: 'bg-purple-100 text-purple-800',
    suspicious: 'bg-red-100 text-red-800',
    normal: 'bg-green-100 text-green-800'
  }[c] || 'bg-gray-100 text-gray-700'
}

function statusLabel(s) {
  return { in_progress: '考试中', submitted: '已提交', graded: '已评分', awaiting_review: '待处理', terminated: '已终止' }[s] || s
}

function eventDot(type) {
  if (type === 'offline_detected' || type === 'overdue') return 'bg-amber-400'
  if (type === 'reconnect' || type === 'session_resume') return 'bg-green-500'
  if (type === 'device_switch') return 'bg-purple-500'
  if (type === 'page_refresh') return 'bg-blue-400'
  if (type.includes('submit')) return 'bg-indigo-500'
  return 'bg-gray-300'
}

function fmt(t) {
  return t ? new Date(t).toLocaleString() : '—'
}
</script>
