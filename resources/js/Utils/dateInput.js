/**
 * Format a browser date for an HTML date input using the operator's local day.
 *
 * Date#toISOString() uses UTC and can silently select yesterday in Palestine
 * shortly after midnight, which is unsafe for posting and operational dates.
 */
export function localDateInput(value = new Date()) {
    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return "";
    }

    const pad = (part) => String(part).padStart(2, "0");

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}
