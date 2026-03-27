let googleMapsPromise = null

export function loadGoogleMaps() {
    if (window.google?.maps) {
        return Promise.resolve(window.google)
    }

    if (googleMapsPromise) {
        return googleMapsPromise
    }

    const apiKey = import.meta.env.VITE_GOOGLE_MAPS_API_KEY

    if (!apiKey) {
        return Promise.reject(new Error('VITE_GOOGLE_MAPS_API_KEY is missing.'))
    }

    googleMapsPromise = new Promise((resolve, reject) => {
        const existing = document.getElementById('google-maps-script')

        if (existing) {
            existing.addEventListener('load', () => resolve(window.google))
            existing.addEventListener('error', () => reject(new Error('Google Maps failed to load.')))
            return
        }

        const script = document.createElement('script')
        script.id = 'google-maps-script'
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&v=weekly`
        script.async = true
        script.defer = true
        script.onload = () => resolve(window.google)
        script.onerror = () => reject(new Error('Google Maps failed to load.'))
        document.head.appendChild(script)
    })

    return googleMapsPromise
}