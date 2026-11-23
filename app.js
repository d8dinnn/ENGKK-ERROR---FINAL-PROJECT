// binary search and merge sort are used by the sales report
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
// merge sort descending by units sold
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
function roundUnits(value) {
    return Math.round(value * 100) / 100;
}
function buildReport() {
    const data = window.salesData || [];
    const from = document.getElementById('dateFrom').value;
    const to = document.getElementById('dateTo').value;
    const rows = document.getElementById('reportRows');
    if (!from || !to || from > to) {
        rows.innerHTML = '<tr><td colspan="3" class="empty">Please choose a start date that comes before the end date.</td></tr>';
        return;
    }

    const start = lowerBoundByDate(data, from);
    const end = upperBoundByDate(data, to);
    const selected = data.slice(start, end);
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
    document.getElementById('reportUnits').textContent = roundUnits(units);
    if (!ranked.length) {
        rows.innerHTML = '<tr><td colspan="3" class="empty">No sales in this period. Try a wider date range.</td></tr>';
        return;
    }
    rows.innerHTML = ranked.map(item =>
        `<tr><td><b>${escapeHtml(item.name)}</b></td><td>${roundUnits(item.units)}</td><td>${peso(item.revenue)}</td></tr>`
    ).join('');
}
function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
}

function addRecipeRow() {
    const container = document.getElementById('recipeRows');
    const first = container.querySelector('.recipe-row');
    const copy = first.cloneNode(true);
    copy.querySelectorAll('input').forEach(i => i.value = '');
    copy.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
    container.appendChild(copy);
}

function removeRecipeRow(btn) {
    const container = document.getElementById('recipeRows');
    if (container.querySelectorAll('.recipe-row').length > 1) {
        btn.closest('.recipe-row').remove();
    }
}

function ymd(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
function setRange(kind) {
    const now = new Date();
    let from = new Date(now);
    if (kind === 'week') from.setDate(now.getDate() - 6);
    else if (kind === 'month') from = new Date(now.getFullYear(), now.getMonth(), 1);
    else if (kind === 'all') {
        const data = window.salesData || [];
        from = data.length ? new Date(data[0].date + 'T00:00:00') : now;
    }
    document.getElementById('dateFrom').value = ymd(from);
    document.getElementById('dateTo').value = ymd(now);
    buildReport();
}

function updateSaleTotal() {
    const sel = document.getElementById('saleItem');
    if (!sel) return;
    const opt = sel.options[sel.selectedIndex];
    const qty = parseFloat(document.getElementById('saleQty').value) || 0;
    const price = parseFloat(opt.dataset.price || 0);
    const max = parseFloat(opt.dataset.max || 0);
    document.getElementById('saleTotal').textContent = peso(price * qty);
    const warn = document.getElementById('saleWarn');
    if (sel.value && qty > max) {
        warn.textContent = 'You only have enough ingredients for ' + max + ' serving(s). Restock first or lower the quantity.';
        warn.hidden = false;
    } else {
        warn.hidden = true;
    }
}

function updatePurchaseTotal() {
    const q = parseFloat(document.getElementById('purchQty').value) || 0;
    const c = parseFloat(document.getElementById('purchCost').value) || 0;
    document.getElementById('purchTotal').textContent = peso(q * c);
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('reportRows')) buildReport();

    if (document.getElementById('saleItem')) {
        ['saleItem', 'saleQty'].forEach(id => {
            document.getElementById(id).addEventListener('input', updateSaleTotal);
            document.getElementById(id).addEventListener('change', updateSaleTotal);
        });
        updateSaleTotal();
    }
    if (document.getElementById('purchQty')) {
        ['purchQty', 'purchCost'].forEach(id => document.getElementById(id).addEventListener('input', updatePurchaseTotal));
        updatePurchaseTotal();
    }

    document.querySelectorAll('form[method="POST"]').forEach(form => {
        form.addEventListener('submit', e => {
            if (e.defaultPrevented) return;
            const btn = form.querySelector('button:not([type="button"])');
            if (btn) setTimeout(() => { btn.disabled = true; btn.textContent = 'Saving…'; }, 0);
        });
    });

    document.querySelectorAll('.alert.success').forEach(a => setTimeout(() => a.classList.add('fade'), 6000));
});