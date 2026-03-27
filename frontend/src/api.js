import axios from 'axios'

const http = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '',
  headers: {
    'X-Requested-With': 'XMLHttpRequest',
    Accept: 'application/json',
  },
})

export function getSettings() {
  return http.get('/api/settings')
}

export function saveSettings(payload) {
  return http.put('/api/settings', payload)
}

export function getGeofences(includeDeleted = false) {
  return http.get('/api/geofences', {
    params: {
      include_deleted: includeDeleted ? 1 : 0,
    },
  })
}

export function createGeofence(payload) {
  return http.post('/api/geofences', payload)
}

export function updateGeofence(id, payload) {
  return http.put(`/api/geofences/${id}`, payload)
}

export function removeGeofence(id) {
  return http.delete(`/api/geofences/${id}`)
}

export function getGpsPoints(params = {}) {
  return http.get('/api/gps-points', { params })
}

export function createGpsPoint(payload) {
  return http.post('/api/gps-points', payload)
}

export function createTestGpsPoint(payload) {
  return http.post('/api/gps-points/test', payload)
}
