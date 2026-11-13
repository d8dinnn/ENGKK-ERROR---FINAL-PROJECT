// Binary search and merge sort are used by the sales report
function lowerBoundByDate(records, target) {
    let low = 0, high = records.length;
    while (low < high) {
        const mid = Math.floor((low + high) / 2);
        if (records[mid].date < target) low = mid + 1;
        else high = mid;
    }
    return low;
}
function upperBoundByDate(records, target) {
    let low = 0, high = records.length;
    while (low < high) {
        const mid = Math.floor((low + high) / 2);
        if (records[mid].date <= target) low = mid + 1;
        else high = mid;
    }
    return low;
}
// Merge sort descending by units sold
function mergeSortProducts(items) {
    if (items.length <= 1) return items;
    const mid = Math.floor(items.length / 2);
    const left = mergeSortProducts(items.slice(0, mid));
    const right = mergeSortProducts(items.slice(mid));
    const merged = [];
    let i = 0, j = 0;
    while (i < left.length && j < right.length) {
        if (left[i].units >= right[j].units) merged.push(left[i++]);
        else merged.push(right[j++]);
    }
    return merged.concat(left.slice(i), right.slice(j));
}
function peso(value) {
    return '₱' + Number(value).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function buildReport() {
    const data = window.salesData || [];
    const from = document.getElementById('dateFrom').value;
    const to = document.getElementById('dateTo').value;
    const rows = document.getElementById('reportRows');
    if (!from || !to || from > to) {
        rows.innerHTML = '<tr><td colspan="3" class="empty">Please select a valid date range.</td></tr>';
        return;
    }
    // The PHP page supplies records ordered by date. Binary search finds the date-range boundaries
    const start = lowerBoundByDate(data, from);
    const end = upperBoundByDate(data, to);
    const selected = data.slice(start, end); // Dynamic array/list of filtered sales.
    let total = 0, units = 0;
    const grouped = {};
    selected.forEach(sale => {
        total += sale.total;
        units += sale.quantity;
        if (!grouped[sale.name]) grouped[sale.name] = {name:sale.name, units:0, revenue:0};
        grouped[sale.name].units += sale.quantity;
        grouped[sale.name].revenue += sale.total;
    });
    const ranked = mergeSortProducts(Object.values(grouped));
    document.getElementById('reportSales').textContent = peso(total);
    document.getElementById('reportUnits').textContent = units;
    if (!ranked.length) {
        rows.innerHTML = '<tr><td colspan="3" class="empty">No sales found for this period.</td></tr>';
        return;
    }
    rows.innerHTML = ranked.map(item =>
        `<tr><td><b>${escapeHtml(item.name)}</b></td><td>${item.units}</td><td>${peso(item.revenue)}</td></tr>`
    ).join('');
}
function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
}
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('reportRows')) buildReport();
});