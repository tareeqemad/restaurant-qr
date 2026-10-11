// These are deduplication identifiers, not authentication secrets. Some LAN
// installations use HTTP, where randomUUID is unavailable but getRandomValues
// is still supported. Keep the fallback compatible with backend UUID rules.
export function createAccountingToken(cryptoApi = globalThis.crypto) {
    if (typeof cryptoApi?.randomUUID === 'function') return cryptoApi.randomUUID()
    const bytes = new Uint8Array(16)
    if (typeof cryptoApi?.getRandomValues === 'function') {
        cryptoApi.getRandomValues(bytes)
    } else {
        for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256)
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40
    bytes[8] = (bytes[8] & 0x3f) | 0x80
    const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
