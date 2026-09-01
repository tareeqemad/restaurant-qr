export function formatMoney(amount, currency) {
    const decimals = Math.max(0, Number(currency?.decimals) || 0);
    const number = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(Number(amount) || 0);

    return `${number} ${currency?.symbol ?? ''}`.trim();
}
