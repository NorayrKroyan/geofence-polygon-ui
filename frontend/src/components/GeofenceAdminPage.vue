<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import {
  createGeofence,
  createTestGpsPoint,
  getGeofences,
  getGpsPoints,
  getSettings,
  removeGeofence,
  saveSettings,
  updateGeofence,
} from '../api'
import { loadGoogleMaps } from '../googleMaps'

const mapElementRef = ref(null)

const geofences = ref([])
const gpsPoints = ref([])
const selectedId = ref(null)
const mapReady = ref(false)
const mapError = ref('')
const drawingMode = ref(false)
const testPointMode = ref(false)
const draftPaths = ref([])
const statusMessage = ref('Click "New Polygon Draft", then click the map to add vertices.')
const geofenceBusy = ref(false)
const settingsBusy = ref(false)
const gpsBusy = ref(false)

const latestGpsPage = ref(1)
const latestGpsPerPage = 5

const geofenceForm = reactive({
  name: '',
  color: '#2563eb',
  is_active: true,
  notes: '',
  speed_limit_kph: '',
  entry_action: '',
  exit_action: '',
  expire_date: '',
})

const settingsForm = reactive({
  default_center_lat: 40.17712,
  default_center_lng: 44.50391,
  default_zoom: 13,
  gps_refresh_seconds: 15,
})

const gpsFilters = reactive({
  device_uuid: '',
  limit: 200,
})

const selectedGeofence = computed(() =>
    geofences.value.find((item) => item.id === selectedId.value) ?? null
)

const activePolygonPaths = computed(() => {
  if (draftPaths.value.length >= 3) {
    return draftPaths.value.map((point) => ({
      lat: Number(point.lat),
      lng: Number(point.lng),
    }))
  }

  const savedPaths = selectedGeofence.value?.geometry_json?.paths
  if (Array.isArray(savedPaths) && savedPaths.length >= 3) {
    return savedPaths.map((point) => ({
      lat: Number(point.lat),
      lng: Number(point.lng),
    }))
  }

  return []
})

const selectedGeofenceTestPoints = computed(() =>
    gpsPoints.value.filter((item) => item.is_test_point && Number(item.tested_geofence_id) === Number(selectedId.value))
)

const latestGpsTotalPages = computed(() => {
  return Math.max(1, Math.ceil(gpsPoints.value.length / latestGpsPerPage))
})

const paginatedLatestGpsRows = computed(() => {
  const start = (latestGpsPage.value - 1) * latestGpsPerPage
  const end = start + latestGpsPerPage
  return gpsPoints.value.slice(start, end)
})

const latestGpsRangeStart = computed(() => {
  if (!gpsPoints.value.length) {
    return 0
  }

  return (latestGpsPage.value - 1) * latestGpsPerPage + 1
})

const latestGpsRangeEnd = computed(() => {
  if (!gpsPoints.value.length) {
    return 0
  }

  return Math.min(latestGpsPage.value * latestGpsPerPage, gpsPoints.value.length)
})

let map = null
let googleRef = null
let mapClickListener = null
let draftPolygon = null
let draftPolyline = null
let gpsTimer = null
let lastHandledTestClickKey = null
let lastHandledTestClickAt = 0

let vertexCircles = []
let staticPolygons = []
let gpsCircles = []
let pathListeners = []
let polygonClickListeners = []

function toNumber(value, fallback = 0) {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : fallback
}

function setStatus(message) {
  statusMessage.value = message
}

function resetGeofenceForm() {
  geofenceForm.name = ''
  geofenceForm.color = '#2563eb'
  geofenceForm.is_active = true
  geofenceForm.notes = ''
  geofenceForm.speed_limit_kph = ''
  geofenceForm.entry_action = ''
  geofenceForm.exit_action = ''
  geofenceForm.expire_date = ''
}

function stopDrawingMode() {
  drawingMode.value = false
}

function stopTestPointMode() {
  testPointMode.value = false
}

function clearMapOverlay(overlay) {
  if (overlay) {
    overlay.setMap(null)
  }
}

function clearPathListeners() {
  pathListeners.forEach((listener) => listener.remove())
  pathListeners = []
}

function clearPolygonClickListeners() {
  polygonClickListeners.forEach((listener) => listener.remove())
  polygonClickListeners = []
}

function clearVertexCircles() {
  vertexCircles.forEach((circle) => circle.setMap(null))
  vertexCircles = []
}

function clearStaticPolygons() {
  staticPolygons.forEach((polygon) => polygon.setMap(null))
  staticPolygons = []
}

function clearGpsOverlays() {
  gpsCircles.forEach((circle) => circle.setMap(null))
  gpsCircles = []
}

function latLngLiteral(point) {
  return {
    lat: Number(point.lat),
    lng: Number(point.lng),
  }
}

function normalizedDraftPaths() {
  return draftPaths.value.map((point) => ({
    lat: Number(Number(point.lat).toFixed(6)),
    lng: Number(Number(point.lng).toFixed(6)),
  }))
}

function syncFormFromSelected() {
  if (!selectedGeofence.value) {
    resetGeofenceForm()
    return
  }

  geofenceForm.name = selectedGeofence.value.name || ''
  geofenceForm.color = selectedGeofence.value.color || '#2563eb'
  geofenceForm.is_active = !!selectedGeofence.value.is_active
  geofenceForm.notes = selectedGeofence.value.notes || ''
  geofenceForm.speed_limit_kph = selectedGeofence.value.speed_limit_kph ?? ''
  geofenceForm.entry_action = selectedGeofence.value.entry_action || ''
  geofenceForm.exit_action = selectedGeofence.value.exit_action || ''
  geofenceForm.expire_date = selectedGeofence.value.expire_date
      ? String(selectedGeofence.value.expire_date).slice(0, 10)
      : ''
}

function zoomToPaths(paths) {
  if (!map || !googleRef || !Array.isArray(paths) || !paths.length) {
    return
  }

  const bounds = new googleRef.maps.LatLngBounds()
  paths.forEach((point) => bounds.extend(latLngLiteral(point)))
  map.fitBounds(bounds)
}

function attachDraftPathListeners(path) {
  clearPathListeners()

  pathListeners.push(
      path.addListener('set_at', syncDraftFromPolygon),
      path.addListener('insert_at', syncDraftFromPolygon),
      path.addListener('remove_at', syncDraftFromPolygon),
  )
}

function syncDraftFromPolygon() {
  if (!draftPolygon) {
    return
  }

  const path = draftPolygon.getPath()
  draftPaths.value = Array.from({ length: path.getLength() }, (_, index) => {
    const point = path.getAt(index)
    return {
      lat: Number(point.lat().toFixed(6)),
      lng: Number(point.lng().toFixed(6)),
    }
  })

  renderVertexCircles()
}

function renderVertexCircles() {
  clearVertexCircles()

  if (!map || !googleRef) {
    return
  }

  vertexCircles = normalizedDraftPaths().map((point, index) => {
    return new googleRef.maps.Circle({
      map,
      center: point,
      radius: 8,
      strokeColor: '#ffffff',
      strokeOpacity: 1,
      strokeWeight: 2,
      fillColor: geofenceForm.color || '#2563eb',
      fillOpacity: 1,
      zIndex: 1000 + index,
    })
  })
}

function getStatusLabel(status) {
  if (status === 'inside') return 'INSIDE'
  if (status === 'edge') return 'ON BORDER'
  return 'OUTSIDE'
}

function getStatusClass(status) {
  if (status === 'inside') return 'result-inside'
  if (status === 'edge') return 'result-edge'
  return 'result-outside'
}

function getPointFillColor(item) {
  if (item.is_test_point) {
    if (item.geofence_check_status === 'inside') return '#16a34a'
    if (item.geofence_check_status === 'edge') return '#d97706'
    return '#dc2626'
  }

  return '#2563eb'
}

function renderGpsPoints() {
  if (!map || !googleRef) {
    return
  }

  clearGpsOverlays()

  gpsCircles = gpsPoints.value.map((item) => {
    const center = {
      lat: Number(item.lat),
      lng: Number(item.lng),
    }

    const circle = new googleRef.maps.Circle({
      map,
      center,
      radius: item.is_test_point ? 7 : 5,
      strokeColor: '#ffffff',
      strokeOpacity: 1,
      strokeWeight: 2,
      fillColor: getPointFillColor(item),
      fillOpacity: 0.95,
      zIndex: item.is_test_point ? 90 : 60,
    })

    const label = item.is_test_point
        ? getStatusLabel(item.geofence_check_status)
        : 'GPS POINT'

    const infoWindow = new googleRef.maps.InfoWindow({
      content: `
        <div style="min-width:220px;font-size:13px;line-height:1.45">
          <div><strong>Device:</strong> ${item.device_uuid ?? ''}</div>
          <div><strong>Lat:</strong> ${Number(item.lat).toFixed(6)}</div>
          <div><strong>Lng:</strong> ${Number(item.lng).toFixed(6)}</div>
          <div><strong>Type:</strong> ${item.is_test_point ? 'TEST POINT' : 'MOBILE POINT'}</div>
          <div><strong>Result:</strong> ${label}</div>
        </div>
      `,
    })

    circle.addListener('click', () => {
      infoWindow.setPosition(center)
      infoWindow.open({ map })
    })

    return circle
  })
}

function renderDraftGeometry() {
  if (!map || !googleRef) {
    return
  }

  clearPathListeners()
  clearPolygonClickListeners()
  clearMapOverlay(draftPolygon)
  clearMapOverlay(draftPolyline)
  draftPolygon = null
  draftPolyline = null
  clearVertexCircles()

  const points = normalizedDraftPaths()

  if (!points.length) {
    return
  }

  if (points.length >= 2) {
    draftPolyline = new googleRef.maps.Polyline({
      map,
      path: points,
      strokeColor: geofenceForm.color || '#2563eb',
      strokeOpacity: 0.95,
      strokeWeight: 2,
      clickable: false,
      zIndex: 20,
    })
  }

  if (points.length >= 3) {
    draftPolygon = new googleRef.maps.Polygon({
      map,
      paths: points,
      strokeColor: geofenceForm.color || '#2563eb',
      strokeOpacity: 1,
      strokeWeight: 3,
      fillColor: geofenceForm.color || '#2563eb',
      fillOpacity: 0.16,
      editable: !testPointMode.value,
      clickable: true,
      zIndex: 30,
    })

    if (!testPointMode.value) {
      attachDraftPathListeners(draftPolygon.getPath())
    } else {
      polygonClickListeners.push(
          draftPolygon.addListener('click', async (event) => {
            if (!event?.latLng) {
              return
            }

            await submitTestPoint({
              lat: Number(event.latLng.lat().toFixed(6)),
              lng: Number(event.latLng.lng().toFixed(6)),
            })
          }),
      )
    }
  }

  renderVertexCircles()
}

function renderStaticGeofences() {
  if (!map || !googleRef) {
    return
  }

  clearStaticPolygons()

  geofences.value.forEach((item) => {
    if (!item || Number(item.id) === Number(selectedId.value)) {
      return
    }

    const paths = Array.isArray(item.geometry_json?.paths) ? item.geometry_json.paths : []
    if (paths.length < 3) {
      return
    }

    const polygon = new googleRef.maps.Polygon({
      map,
      paths,
      strokeColor: item.color || '#2563eb',
      strokeOpacity: 0.85,
      strokeWeight: 2,
      fillColor: item.color || '#2563eb',
      fillOpacity: 0.10,
      editable: false,
      clickable: true,
      zIndex: 10,
    })

    polygon.addListener('click', () => {
      selectGeofence(item)
    })

    staticPolygons.push(polygon)
  })
}

function restartGpsTimer() {
  if (gpsTimer) {
    clearInterval(gpsTimer)
  }

  gpsTimer = setInterval(() => {
    loadGpsPoints().catch(() => {})
  }, Math.max(5, toNumber(settingsForm.gps_refresh_seconds, 15)) * 1000)
}

function shouldSkipDuplicateTestClick(point) {
  const key = `${Number(point.lat).toFixed(6)}:${Number(point.lng).toFixed(6)}`
  const now = Date.now()

  if (lastHandledTestClickKey === key && now - lastHandledTestClickAt < 300) {
    return true
  }

  lastHandledTestClickKey = key
  lastHandledTestClickAt = now

  return false
}

function goToLatestGpsPage(page) {
  latestGpsPage.value = Math.min(latestGpsTotalPages.value, Math.max(1, page))
}

async function submitTestPoint(point) {
  if (!testPointMode.value || !selectedId.value) {
    return
  }

  if (shouldSkipDuplicateTestClick(point)) {
    return
  }

  try {
    const response = await createTestGpsPoint({
      tested_geofence_id: selectedId.value,
      device_uuid: 'manual-test-ui',
      lat: point.lat,
      lng: point.lng,
    })

    const saved = response?.data?.data
    if (saved) {
      gpsPoints.value = [saved, ...gpsPoints.value]
      renderGpsPoints()
      setStatus(`Test point saved. Result: ${getStatusLabel(saved.geofence_check_status)}.`)
    }
  } catch (error) {
    setStatus(error?.response?.data?.message || 'Failed to save test point.')
  }
}

function startNewDraft() {
  stopTestPointMode()
  selectedId.value = null
  resetGeofenceForm()
  draftPaths.value = []
  renderDraftGeometry()
  drawingMode.value = true
  setStatus('Drawing mode enabled. Click the map to add polygon points.')
}

function completeDraft() {
  if (draftPaths.value.length < 3) {
    setStatus('Polygon draft needs at least 3 points before it can be saved.')
    return
  }

  stopDrawingMode()
  renderDraftGeometry()
  setStatus('Draft completed. Save it, or enable test point mode.')
}

function removeLastVertex() {
  if (!draftPaths.value.length) {
    return
  }

  draftPaths.value = draftPaths.value.slice(0, -1)
  renderDraftGeometry()
  setStatus(`Last point removed. ${draftPaths.value.length} point(s) remain.`)
}

function clearDraft() {
  draftPaths.value = []
  clearPathListeners()
  clearPolygonClickListeners()
  clearMapOverlay(draftPolygon)
  clearMapOverlay(draftPolyline)
  draftPolygon = null
  draftPolyline = null
  clearVertexCircles()
  setStatus('Draft cleared.')
}

function enableTestPointMode() {
  if (!selectedId.value || activePolygonPaths.value.length < 3) {
    setStatus('Save or select a geofence first, then enable test point mode.')
    return
  }

  stopDrawingMode()
  testPointMode.value = true
  renderDraftGeometry()
  setStatus('Test point mode enabled. Click inside or outside the selected geofence to save a test point.')
}

function clearSelectedTestPoints() {
  if (!selectedId.value) {
    return
  }

  gpsPoints.value = gpsPoints.value.filter((item) => {
    return !item.is_test_point || Number(item.tested_geofence_id) !== Number(selectedId.value)
  })

  renderGpsPoints()
  setStatus('Visible selected-geofence test points cleared from the UI list. Database rows were not deleted.')
}

function selectGeofence(item) {
  if (!item) {
    return
  }

  selectedId.value = item.id
  syncFormFromSelected()

  draftPaths.value = Array.isArray(item.geometry_json?.paths)
      ? item.geometry_json.paths.map((point) => ({
        lat: Number(point.lat),
        lng: Number(point.lng),
      }))
      : []

  stopDrawingMode()
  renderDraftGeometry()
  renderStaticGeofences()
  zoomToPaths(draftPaths.value)
  setStatus('Existing geofence loaded. You can edit it or test points against it.')
}

async function loadSettings() {
  try {
    const response = await getSettings()
    const payload = response?.data?.data || {}

    settingsForm.default_center_lat = toNumber(payload.default_center_lat, 40.17712)
    settingsForm.default_center_lng = toNumber(payload.default_center_lng, 44.50391)
    settingsForm.default_zoom = toNumber(payload.default_zoom, 13)
    settingsForm.gps_refresh_seconds = toNumber(payload.gps_refresh_seconds, 15)
  } catch (error) {
    mapError.value = error?.response?.data?.message || 'Failed to load settings.'
  }
}

async function loadGeofences() {
  try {
    const response = await getGeofences(false)
    geofences.value = Array.isArray(response?.data?.data) ? response.data.data : []

    if (!geofences.value.length) {
      selectedId.value = null
      renderStaticGeofences()
      return
    }

    const current = selectedId.value
        ? geofences.value.find((item) => Number(item.id) === Number(selectedId.value))
        : null

    if (current) {
      selectGeofence(current)
    } else {
      selectGeofence(geofences.value[0])
    }
  } catch (error) {
    geofences.value = []
    setStatus(error?.response?.data?.message || 'Failed to load geofences.')
  }
}

async function loadGpsPoints() {
  gpsBusy.value = true

  try {
    const response = await getGpsPoints({
      device_uuid: gpsFilters.device_uuid || undefined,
      limit: gpsFilters.limit || 200,
    })

    gpsPoints.value = Array.isArray(response?.data?.data) ? response.data.data : []
    latestGpsPage.value = 1
    renderGpsPoints()
  } catch (error) {
    gpsPoints.value = []
    latestGpsPage.value = 1
    setStatus(error?.response?.data?.message || 'Failed to load GPS points.')
  } finally {
    gpsBusy.value = false
  }
}

async function handleSaveGeofence() {
  if (draftPaths.value.length < 3) {
    setStatus('Cannot save: polygon requires at least 3 points.')
    return
  }

  geofenceBusy.value = true

  try {
    const payload = {
      name: geofenceForm.name,
      color: geofenceForm.color,
      notes: geofenceForm.notes,
      is_active: geofenceForm.is_active,
      speed_limit_kph: geofenceForm.speed_limit_kph === '' ? null : Number(geofenceForm.speed_limit_kph),
      entry_action: geofenceForm.entry_action || null,
      exit_action: geofenceForm.exit_action || null,
      expire_date: geofenceForm.expire_date || null,
      geometry_json: {
        paths: normalizedDraftPaths(),
      },
    }

    const response = selectedId.value
        ? await updateGeofence(selectedId.value, payload)
        : await createGeofence(payload)

    const saved = response?.data?.data

    if (saved) {
      selectedId.value = saved.id
      await loadGeofences()
      selectGeofence(saved)
      setStatus(selectedGeofence.value ? 'Geofence saved successfully.' : 'Geofence created successfully.')
    }
  } catch (error) {
    const message =
        error?.response?.data?.message ||
        (error?.response?.data?.errors ? JSON.stringify(error.response.data.errors) : null) ||
        'Failed to save geofence.'

    setStatus(message)
  } finally {
    geofenceBusy.value = false
  }
}

async function handleDeleteGeofence() {
  if (!selectedId.value) {
    setStatus('Select a geofence before deleting.')
    return
  }

  geofenceBusy.value = true

  try {
    await removeGeofence(selectedId.value)
    selectedId.value = null
    resetGeofenceForm()
    clearDraft()
    stopTestPointMode()
    await loadGeofences()
    await loadGpsPoints()
    setStatus('Geofence deleted successfully.')
  } catch (error) {
    setStatus(error?.response?.data?.message || 'Failed to delete geofence.')
  } finally {
    geofenceBusy.value = false
  }
}

async function handleSaveSettings() {
  settingsBusy.value = true

  try {
    await saveSettings({
      default_center_lat: toNumber(settingsForm.default_center_lat, 40.17712),
      default_center_lng: toNumber(settingsForm.default_center_lng, 44.50391),
      default_zoom: toNumber(settingsForm.default_zoom, 13),
      gps_refresh_seconds: toNumber(settingsForm.gps_refresh_seconds, 15),
    })

    if (map) {
      map.setCenter({
        lat: toNumber(settingsForm.default_center_lat, 40.17712),
        lng: toNumber(settingsForm.default_center_lng, 44.50391),
      })
      map.setZoom(toNumber(settingsForm.default_zoom, 13))
    }

    restartGpsTimer()
    setStatus('Settings saved successfully.')
  } catch (error) {
    setStatus(error?.response?.data?.message || 'Failed to save settings.')
  } finally {
    settingsBusy.value = false
  }
}

async function initMap() {
  try {
    await nextTick()
    googleRef = await loadGoogleMaps()

    const mapElement = mapElementRef.value
    if (!mapElement) {
      throw new Error('Map container ref was not found.')
    }

    map = new googleRef.maps.Map(mapElement, {
      center: {
        lat: toNumber(settingsForm.default_center_lat, 40.17712),
        lng: toNumber(settingsForm.default_center_lng, 44.50391),
      },
      zoom: toNumber(settingsForm.default_zoom, 13),
      mapTypeControl: true,
      streetViewControl: false,
      fullscreenControl: true,
      mapId: import.meta.env.VITE_GOOGLE_MAPS_MAP_ID || undefined,
    })

    mapClickListener = map.addListener('click', async (event) => {
      const clickedPoint = {
        lat: Number(event.latLng.lat().toFixed(6)),
        lng: Number(event.latLng.lng().toFixed(6)),
      }

      if (drawingMode.value) {
        draftPaths.value = [...draftPaths.value, clickedPoint]
        renderDraftGeometry()
        setStatus(`Point added. Draft now has ${draftPaths.value.length} vertices.`)
        return
      }

      if (testPointMode.value) {
        await submitTestPoint(clickedPoint)
      }
    })

    mapReady.value = true
    renderStaticGeofences()
    renderDraftGeometry()
    renderGpsPoints()
  } catch (error) {
    mapError.value = error?.message || 'Google Maps failed to load.'
  }
}

watch(
    () => geofenceForm.color,
    () => {
      renderDraftGeometry()
      renderStaticGeofences()
    },
)

watch(selectedId, () => {
  renderStaticGeofences()
})

watch(selectedGeofence, () => {
  syncFormFromSelected()
})

watch(
    () => gpsPoints.value.length,
    () => {
      if (latestGpsPage.value > latestGpsTotalPages.value) {
        latestGpsPage.value = latestGpsTotalPages.value
      }
    },
)

onMounted(async () => {
  await loadSettings()
  await initMap()
  await loadGeofences()
  await loadGpsPoints()
  restartGpsTimer()
})

onBeforeUnmount(() => {
  if (gpsTimer) {
    clearInterval(gpsTimer)
  }

  if (mapClickListener) {
    mapClickListener.remove()
  }

  clearPathListeners()
  clearPolygonClickListeners()
  clearStaticPolygons()
  clearGpsOverlays()
  clearVertexCircles()
  clearMapOverlay(draftPolygon)
  clearMapOverlay(draftPolyline)
})
</script>

<template>
  <div class="layout compact-layout">
    <aside class="sidebar">
      <div class="panel compact-panel">
        <h1>Polygon Geofence Admin</h1>
        <div class="status">{{ statusMessage }}</div>
        <div v-if="mapError" class="status status-error">{{ mapError }}</div>
      </div>

      <div class="panel compact-panel">
        <div class="panel-title-row">
          <h2>Geofence Editor</h2>
          <span class="badge">{{ selectedId ? `Editing #${selectedId}` : 'New' }}</span>
        </div>

        <label class="field">
          <span>GeoFence Name</span>
          <input v-model="geofenceForm.name" type="text" placeholder="Zone A" />
        </label>

        <div class="settings-grid compact-grid">
          <label class="field">
            <span>Color</span>
            <input v-model="geofenceForm.color" type="color" />
          </label>
        </div>

        <label class="field checkbox-field">
          <input v-model="geofenceForm.is_active" type="checkbox" />
          <span>Active</span>
        </label>

        <label class="field">
          <span>Notes</span>
          <textarea v-model="geofenceForm.notes" rows="3" placeholder="Optional notes"></textarea>
        </label>

        <div class="button-grid compact-button-grid">
          <button class="btn compact-btn" type="button" @click="startNewDraft">New Polygon Draft</button>
          <button class="btn compact-btn" type="button" @click="completeDraft">Complete Draft</button>
          <button class="btn btn-light compact-btn" type="button" @click="removeLastVertex">Undo Last Point</button>
          <button class="btn btn-light compact-btn" type="button" @click="clearDraft">Clear Draft</button>
          <button class="btn btn-primary compact-btn" type="button" :disabled="geofenceBusy" @click="handleSaveGeofence">
            {{ geofenceBusy ? 'Saving...' : 'Save Geofence' }}
          </button>
          <button class="btn btn-danger compact-btn" type="button" :disabled="geofenceBusy || !selectedId" @click="handleDeleteGeofence">
            Delete Selected
          </button>
        </div>

        <div class="draft-meta compact-meta">
          <div><strong>Drawing mode:</strong> {{ drawingMode ? 'ON' : 'OFF' }}</div>
          <div><strong>Test point mode:</strong> {{ testPointMode ? 'ON' : 'OFF' }}</div>
          <div><strong>Draft vertices:</strong> {{ draftPaths.length }}</div>
          <div v-if="selectedGeofence">
            <strong>Computed center:</strong>
            {{ selectedGeofence.center_point_lat ?? '—' }}, {{ selectedGeofence.center_point_lng ?? '—' }}
          </div>
        </div>

        <div class="points-list compact-list">
          <div class="points-header">Polygon Points</div>
          <div v-if="!draftPaths.length" class="muted small">No polygon points yet.</div>
          <div
              v-for="(point, index) in draftPaths"
              :key="`${index}-${point.lat}-${point.lng}`"
              class="point-row compact-row"
          >
            <span>#{{ index + 1 }}</span>
            <span>{{ Number(point.lat).toFixed(6) }}</span>
            <span>{{ Number(point.lng).toFixed(6) }}</span>
          </div>
        </div>
      </div>

      <div class="panel compact-panel">
        <div class="panel-title-row">
          <h2>Selected Geofence Test Points</h2>
          <span class="badge">{{ selectedGeofenceTestPoints.length }}</span>
        </div>

        <div class="inline-actions compact-inline-actions">
          <button class="btn btn-primary compact-btn" type="button" @click="enableTestPointMode">Enable Test Point Mode</button>
          <button class="btn btn-light compact-btn" type="button" @click="stopTestPointMode">Stop Test Point Mode</button>
          <button class="btn btn-light compact-btn" type="button" :disabled="!selectedGeofenceTestPoints.length" @click="clearSelectedTestPoints">
            Clear Visible Test Points
          </button>
        </div>

        <div class="helper-text compact-helper">
          Test clicks are saved to <code>gps_points</code> with point_source = manual_test and geofence_check_status = inside / edge / outside.
        </div>

        <div class="gps-list compact-list">
          <div v-if="!selectedGeofenceTestPoints.length" class="muted small">No saved test points for the selected geofence yet.</div>
          <div v-for="item in selectedGeofenceTestPoints" :key="item.id" class="gps-row compact-row">
            <div class="gps-top">
              <strong>Point #{{ item.id }}</strong>
              <span :class="getStatusClass(item.geofence_check_status)">
                {{ getStatusLabel(item.geofence_check_status) }}
              </span>
            </div>
            <div class="gps-coords">{{ Number(item.lat).toFixed(6) }}, {{ Number(item.lng).toFixed(6) }}</div>
          </div>
        </div>
      </div>

      <div class="panel compact-panel">
        <div class="panel-title-row">
          <h2>Saved Geofences</h2>
          <span class="badge">{{ geofences.length }}</span>
        </div>

        <div v-if="!geofences.length" class="muted small">No geofences saved yet.</div>

        <button
            v-for="item in geofences"
            :key="item.id"
            type="button"
            class="list-item compact-list-item"
            :class="{ active: selectedId === item.id }"
            @click="selectGeofence(item)"
        >
          <span class="list-item-name">{{ item.name }}</span>
          <span class="list-item-meta">
            {{ item.center_point_lat ?? '—' }}, {{ item.center_point_lng ?? '—' }}
          </span>
        </button>
      </div>
    </aside>

    <main class="map-area">
      <div class="toolbar compact-toolbar">
        <div class="toolbar-group">
          <label>
            <span>Device UUID</span>
            <input v-model="gpsFilters.device_uuid" type="text" placeholder="optional filter" />
          </label>
          <label>
            <span>GPS Limit</span>
            <input v-model.number="gpsFilters.limit" type="number" min="1" max="1000" />
          </label>
        </div>

        <div class="toolbar-group toolbar-actions">
          <button class="btn btn-light compact-btn" type="button" :disabled="gpsBusy" @click="loadGpsPoints">
            {{ gpsBusy ? 'Refreshing...' : 'Refresh GPS Points' }}
          </button>
        </div>
      </div>

      <div class="map-shell">
        <div ref="mapElementRef" class="map-canvas"></div>
        <div v-show="!mapReady && !mapError" class="map-overlay">Loading Google Maps...</div>
      </div>

      <div class="bottom-panels compact-bottom-panels">
        <section class="panel bottom-panel compact-panel">
          <div class="panel-title-row">
            <h2>Map Settings</h2>
          </div>

          <div class="settings-grid compact-grid">
            <label class="field">
              <span>Default Center Lat</span>
              <input v-model.number="settingsForm.default_center_lat" type="number" step="0.000001" />
            </label>
            <label class="field">
              <span>Default Center Lng</span>
              <input v-model.number="settingsForm.default_center_lng" type="number" step="0.000001" />
            </label>
            <label class="field">
              <span>Default Zoom</span>
              <input v-model.number="settingsForm.default_zoom" type="number" min="1" max="20" />
            </label>
            <label class="field">
              <span>GPS Refresh Seconds</span>
              <input v-model.number="settingsForm.gps_refresh_seconds" type="number" min="5" max="3600" />
            </label>
          </div>

          <button class="btn btn-primary compact-btn" type="button" :disabled="settingsBusy" @click="handleSaveSettings">
            {{ settingsBusy ? 'Saving...' : 'Save Settings' }}
          </button>
        </section>

        <section class="panel bottom-panel compact-panel">
          <div class="panel-title-row">
            <h2>Latest GPS Rows</h2>
            <span class="badge">{{ gpsPoints.length }}</span>
          </div>

          <div class="gps-list compact-list">
            <div v-if="!gpsPoints.length" class="muted small">No gps_points rows loaded.</div>
            <div v-for="item in paginatedLatestGpsRows" :key="`gps-${item.id}`" class="gps-row compact-row">
              <div class="gps-top">
                <strong>{{ item.device_uuid }}</strong>
                <span>{{ item.point_source }}</span>
              </div>
              <div class="gps-coords">{{ Number(item.lat).toFixed(6) }}, {{ Number(item.lng).toFixed(6) }}</div>
              <div class="gps-subline">
                Status:
                <span :class="getStatusClass(item.geofence_check_status || 'outside')">
                  {{ getStatusLabel(item.geofence_check_status || 'outside') }}
                </span>
              </div>
            </div>
          </div>

          <div v-if="gpsPoints.length" class="pagination-row">
            <div class="pagination-meta">
              Showing {{ latestGpsRangeStart }}-{{ latestGpsRangeEnd }} of {{ gpsPoints.length }}
            </div>

            <div class="pagination-actions">
              <button
                  class="btn btn-light compact-btn compact-page-btn"
                  type="button"
                  :disabled="latestGpsPage <= 1"
                  @click="goToLatestGpsPage(latestGpsPage - 1)"
              >
                Prev
              </button>

              <span class="badge">Page {{ latestGpsPage }} / {{ latestGpsTotalPages }}</span>

              <button
                  class="btn btn-light compact-btn compact-page-btn"
                  type="button"
                  :disabled="latestGpsPage >= latestGpsTotalPages"
                  @click="goToLatestGpsPage(latestGpsPage + 1)"
              >
                Next
              </button>
            </div>
          </div>
        </section>
      </div>
    </main>
  </div>
</template>

<style scoped>
.compact-layout {
  gap: 10px;
}

.compact-panel {
  padding: 10px 12px;
  border-radius: 12px;
}

.compact-panel h1 {
  margin: 0 0 6px;
  font-size: 18px;
  line-height: 1.2;
}

.compact-panel h2 {
  margin: 0;
  font-size: 14px;
  line-height: 1.2;
}

.status {
  margin-top: 6px;
  padding: 6px 8px;
  font-size: 12px;
  line-height: 1.35;
}

.badge {
  padding: 3px 7px;
  font-size: 11px;
  line-height: 1.2;
}

.field {
  gap: 4px;
  margin-bottom: 8px;
}

.field > span,
.toolbar label > span,
.points-header,
.helper-text,
.pagination-meta {
  font-size: 12px;
  line-height: 1.3;
}

.field input,
.field textarea,
.toolbar input {
  padding: 7px 9px;
  font-size: 13px;
  line-height: 1.3;
  border-radius: 8px;
}

.field textarea {
  min-height: 64px;
}

.checkbox-field {
  gap: 8px;
}

.compact-grid {
  gap: 8px;
}

.compact-button-grid {
  gap: 8px;
}

.compact-btn {
  min-height: 34px;
  padding: 7px 10px;
  font-size: 12px;
  line-height: 1.2;
  border-radius: 8px;
}

.compact-page-btn {
  min-width: 68px;
}

.compact-meta {
  margin-top: 8px;
  display: grid;
  gap: 4px;
  font-size: 12px;
  line-height: 1.35;
}

.compact-list {
  margin-top: 8px;
}

.compact-row {
  padding: 7px 8px;
  border-radius: 8px;
}

.point-row {
  gap: 8px;
  font-size: 12px;
  line-height: 1.25;
}

.gps-row {
  margin-bottom: 6px;
}

.gps-top,
.gps-coords,
.gps-subline {
  font-size: 12px;
  line-height: 1.3;
}

.compact-inline-actions {
  gap: 8px;
  flex-wrap: wrap;
}

.compact-helper {
  margin-top: 8px;
  padding: 7px 8px;
  border-radius: 8px;
  line-height: 1.35;
}

.compact-list-item {
  padding: 8px 10px;
  border-radius: 9px;
  font-size: 12px;
  line-height: 1.3;
}

.compact-toolbar {
  padding: 8px 10px;
  gap: 8px;
  border-radius: 12px;
}

.compact-toolbar .toolbar-group {
  gap: 8px;
}

.compact-bottom-panels {
  gap: 10px;
}

.pagination-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 8px;
  padding-top: 8px;
}

.pagination-actions {
  display: flex;
  align-items: center;
  gap: 6px;
}

@media (max-width: 768px) {
  .pagination-row {
    flex-direction: column;
    align-items: stretch;
  }

  .pagination-actions {
    justify-content: space-between;
  }

  .compact-button-grid {
    grid-template-columns: 1fr;
  }
}
</style>