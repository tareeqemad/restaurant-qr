export function confirmsPayment(data, operation) {
    return data?.submission_state === 'committed'
        && data.operation_token === operation.token
        && Number(data.invoice_id) === Number(operation.invoiceId)
        && Number.isInteger(Number(data.payment_id)) && Number(data.payment_id) > 0;
}

export function confirmsCustody(data, operation) {
    return data?.submission_state === 'committed'
        && data.operation_token === operation.token
        && Number(data.shift?.id) === Number(operation.shiftId)
        && Number.isInteger(Number(data.movement_id)) && Number(data.movement_id) > 0;
}

export function confirmsRejection(data, operation) {
    return data?.ok === false && data.submission_state === 'rejected'
        && data.operation_token === operation.token && typeof data.message === 'string' && data.message.length > 0;
}

export function readCustodyRecovery(storage, key) {
    const raw = storage.getItem(key);
    if (!raw) return null;
    const operation = JSON.parse(raw);
    if (typeof operation.token !== 'string' || !operation.token || !Number.isInteger(operation.shiftId)
        || !(Number(operation.payload?.amount) > 0) || !['in', 'out'].includes(operation.payload?.direction)
        || !operation.payload.reason || !operation.payload.reference) throw new Error('سجل حركة العهدة غير صالح.');
    return operation;
}
