<?php
require_once 'auth.php';
require_once 'algorithms.php';

// Format stock quantities: "pcs" are whole items, so show 100 instead of 100.00.
// Other units (kg, L, ...) keep 2 decimals.
function qty($value, $unit = ''): string {
    $number = (float)$value;
    if (strtolower(trim((string)$unit)) === 'pcs') {
        return fmod($number, 1.0) == 0.0
            ? number_format($number, 0, '.', '')
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
    return number_format($number, 2, '.', '');
}

function postNumber($key): float {
    return filter_var($_POST[$key] ?? null, FILTER_VALIDATE_FLOAT) !== false
        ? (float)$_POST[$key] : -1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_ingredient') {
            $name = trim($_POST['name'] ?? '');
            $unit = trim($_POST['unit'] ?? 'pcs');
            $qty = postNumber('quantity');
            $reorder = postNumber('reorder_level');
            if ($name === '' || $qty < 0 || $reorder < 0) {
                throw new Exception('Enter a name and valid non-negative quantities.');
            }
            $stmt = $pdo->prepare("INSERT INTO ingredients(name, unit, quantity, reorder_level) VALUES(?,?,?,?)");
            $stmt->execute([$name, $unit, $qty, $reorder]);
            $_SESSION['flash'] = 'Ingredient added.';
        } elseif ($action === 'add_menu') {
            $name = trim($_POST['name'] ?? '');
            $price = postNumber('price');
            $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
            $ingredientQty = postNumber('ingredient_qty');
            if ($name === '' || $price <= 0 || $ingredientId <= 0 || $ingredientQty <= 0) {
                throw new Exception('Complete all menu item fields with valid positive values.');
            }
            $pdo->prepare("INSERT INTO menu_items(name, price, ingredient_id, ingredient_qty) VALUES(?,?,?,?)")
                ->execute([$name, $price, $ingredientId, $ingredientQty]);
            $_SESSION['flash'] = 'Menu item added.';
        } elseif ($action === 'purchase') {
            $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
            $qty = postNumber('quantity');
            $cost = postNumber('unit_cost');
            if ($ingredientId <= 0 || $qty <= 0 || $cost < 0) {
                throw new Exception('Enter a valid ingredient, quantity, and cost.');
            }
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO purchases(ingredient_id, quantity, unit_cost) VALUES(?,?,?)")
                ->execute([$ingredientId, $qty, $cost]);
            $pdo->prepare("UPDATE ingredients SET quantity = quantity + ? WHERE id = ?")
                ->execute([$qty, $ingredientId]);
            $pdo->commit();
            $_SESSION['flash'] = 'Purchase recorded and stock updated.';
        } elseif ($action === 'sale') {
            $menuId = (int)($_POST['menu_item_id'] ?? 0);
            $qty = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($menuId <= 0 || $qty === false || $qty <= 0) {
                throw new Exception('Choose a menu item and enter a quantity greater than zero.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
            $stmt->execute([$menuId]);
            $item = $stmt->fetch();
            if (!$item) {
                throw new Exception('Menu item not found.');
            }

            // Hash-table lookup is used here to retrieve the ingredient needed for this sale.
            $lockedIngredients = $pdo->query("SELECT * FROM ingredients FOR UPDATE")->fetchAll();
            $saleStockTable = new IngredientHashTable();
            foreach ($lockedIngredients as $ingredientRow) {
                $saleStockTable->insert($ingredientRow['id'], $ingredientRow);
            }
            $ingredient = $saleStockTable->get($item['ingredient_id']);
            if (!$ingredient) {
                throw new Exception('The linked ingredient could not be found.');
            }

            $needed = (float)$item['ingredient_qty'] * $qty;
            if ((float)$ingredient['quantity'] < $needed) {
                throw new Exception('Not enough ' . $ingredient['name'] . ' in stock.');
            }
            $total = (float)$item['price'] * $qty;
            $pdo->prepare("INSERT INTO sales(menu_item_id, quantity, total) VALUES(?,?,?)")
                ->execute([$menuId, $qty, $total]);
            $pdo->prepare("UPDATE ingredients SET quantity = quantity - ? WHERE id = ?")
                ->execute([$needed, $item['ingredient_id']]);
            $pdo->commit();
            $_SESSION['flash'] = 'Sale recorded. Ingredient stock was automatically deducted.';
        } elseif ($action === 'expense') {
            $description = trim($_POST['description'] ?? '');
            $amount = postNumber('amount');
            $date = $_POST['expense_date'] ?? date('Y-m-d');
            if ($description === '' || $amount <= 0) {
                throw new Exception('Enter an expense description and an amount greater than zero.');
            }
            $pdo->prepare("INSERT INTO expenses(description, amount, expense_date) VALUES(?,?,?)")
                ->execute([$description, $amount, $date]);
            $_SESSION['flash'] = 'Expense recorded.';
        } elseif ($action === 'delete_ingredient') {
            $id = (int)($_POST['id'] ?? 0);
            // Prevent deleting ingredients already used by menu items or purchases.
            $check = $pdo->prepare("SELECT (SELECT COUNT(*) FROM menu_items WHERE ingredient_id = ?) + (SELECT COUNT(*) FROM purchases WHERE ingredient_id = ?)");
            $check->execute([$id, $id]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception('This ingredient is linked to menu items or purchases and cannot be deleted.');
            }
            $pdo->prepare("DELETE FROM ingredients WHERE id = ?")->execute([$id]);
            $_SESSION['flash'] = 'Ingredient deleted.';
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = $ex->getMessage();
    }
    redirect('index.php?page=' . urlencode($_POST['return_page'] ?? 'dashboard'));
}

$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard', 'inventory', 'menu', 'purchases', 'sales', 'expenses', 'reports'];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

$ingredients = $pdo->query("SELECT * FROM ingredients ORDER BY name")->fetchAll();
$menuItems = $pdo->query("SELECT m.*, i.name AS ingredient_name, i.unit, i.quantity AS stock FROM menu_items m JOIN ingredients i ON i.id = m.ingredient_id ORDER BY m.name")->fetchAll();
$sales = $pdo->query("SELECT s.*, m.name AS item_name FROM sales s JOIN menu_items m ON m.id = s.menu_item_id ORDER BY s.sold_at")->fetchAll();
$expenses = $pdo->query("SELECT * FROM expenses ORDER BY expense_date DESC")->fetchAll();

$todaySales = (float)$pdo->query("SELECT COALESCE(SUM(total), 0) FROM sales WHERE DATE(sold_at) = CURDATE()")->fetchColumn();
$todayExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = CURDATE()")->fetchColumn();
$ingredientCount = count($ingredients);
$lowStock = linearReorderSearch($ingredients);

$stockTable = new IngredientHashTable();
foreach ($ingredients as $ingredient) {
    $stockTable->insert($ingredient['id'], $ingredient);
}

$heap = new MinHeap();
foreach ($lowStock as $ingredient) {
    $heap->insert([
        'priority' => (float)$ingredient['quantity'],
        'name' => $ingredient['name'],
        'quantity' => $ingredient['quantity'],
        'unit' => $ingredient['unit']
    ]);
}
$urgentAlerts = [];
while (!$heap->isEmpty()) {
    $urgentAlerts[] = $heap->removeMin();
}

$allTimeSales = (float)$pdo->query("SELECT COALESCE(SUM(total), 0) FROM sales")->fetchColumn();
$allTimeExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses")->fetchColumn();
$topProducts = $pdo->query("SELECT m.name, SUM(s.quantity) AS units, SUM(s.total) AS revenue FROM sales s JOIN menu_items m ON m.id = s.menu_item_id GROUP BY m.id ORDER BY units DESC LIMIT 10")->fetchAll();

$flash = $_SESSION['flash'] ?? ''; 
unset($_SESSION['flash']);
$error = $_SESSION['error'] ?? ''; 
unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>4M’s Express | <?= e(ucfirst($page)) ?></title>
    <link rel="stylesheet" href="style.css">
    <script defer src="app.js"></script>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <a class="brand" href="index.php">
            <span class="brand-mark small">4M</span>
            <span><b>4M’s Express</b><small>STALL MANAGEMENT</small></span>
        </a>
        <div class="nav-label">WORKSPACE</div>
        <nav>
            <a class="<?= $page === 'dashboard' ? 'active' : '' ?>" href="?page=dashboard">⌂ <span>Dashboard</span></a>
            <a class="<?= $page === 'inventory' ? 'active' : '' ?>" href="?page=inventory">▦ <span>Inventory</span></a>
            <a class="<?= $page === 'menu' ? 'active' : '' ?>" href="?page=menu">☷ <span>Menu</span></a>
            <a class="<?= $page === 'purchases' ? 'active' : '' ?>" href="?page=purchases">＋ <span>Stock Purchases</span></a>
            <a class="<?= $page === 'sales' ? 'active' : '' ?>" href="?page=sales">₱ <span>Record Sales</span></a>
            <a class="<?= $page === 'expenses' ? 'active' : '' ?>" href="?page=expenses">↗ <span>Expenses</span></a>
            <a class="<?= $page === 'reports' ? 'active' : '' ?>" href="?page=reports">▤ <span>Analytics & Reports</span></a>
        </nav>
        <div class="sidebar-bottom"> 
            <?php 
                $userName = $_SESSION['name'] ?? 'Admin';
                $avatarLetter = strtoupper($userName[0]);
            ?>
            <div class="user-chip">
                <div class="avatar"><?= e($avatarLetter) ?></div>
                <span><b><?= e($userName) ?></b><small>Stall Owner</small></span>
            </div>
            <a class="logout" href="logout.php">↪ Log out</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <p class="eyebrow">Engkk Error</p>
                <h1><?= e(ucfirst($page === 'reports' ? 'Analytics & Reports' : $page)) ?></h1>
            </div>
            <div class="top-date"><?= date('D, M j, Y') ?></div>
        </header>

        <?php if ($flash): ?>
            <div class="alert success"><?= e($flash) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert danger"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($page === 'dashboard'): ?>
            <section class="welcome">
                <div>
                    <p class="eyebrow">YOUR BUSINESS AT A GLANCE</p>
                    <h2>Good day! Ready for business?</h2>
                    <p>Keep tracking to make Keep growing.</p>
                </div>
            </section>
            
            <div class="stats-grid">
                <div class="stat-card"><span>Today’s sales</span><strong><?= money($todaySales) ?></strong><small>Recorded today</small></div>
                <div class="stat-card"><span>Today’s expenses</span><strong><?= money($todayExpenses) ?></strong><small>Recorded today</small></div>
                <div class="stat-card"><span>Ingredients</span><strong><?= $ingredientCount ?></strong><small>Tracked stock items</small></div>
                <div class="stat-card"><span>Low-stock alerts</span><strong><?= count($lowStock) ?></strong><small>Needs attention</small></div>
            </div>

            <div class="content-grid">
                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Low-stock ingredients</h3>
                            <p>Items at or below their reorder level</p>
                        </div>
                        <a href="?page=inventory">View inventory →</a>
                    </div>
                    <?php if (!$urgentAlerts): ?>
                        <div class="empty">No low-stock ingredients. Your shelves are behaving.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Ingredient</th>
                                        <th>Available</th>
                                        <th>Priority</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($urgentAlerts as $a): ?>
                                        <tr>
                                            <td><b><?= e($a['name']) ?></b></td>
                                            <td><?= e(qty($a['quantity'], $a['unit'])) ?> <?= e($a['unit']) ?></td>
                                            <td><span class="pill warning">Reorder soon</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Quick actions</h3>
                            <p>Common daily tasks</p>
                        </div>
                    </div>
                    <div class="quick-links">
                        <a href="?page=sales"><span>₱</span><b>Record a sale</b><small>Log a customer order</small></a>
                        <a href="?page=purchases"><span>＋</span><b>Add stock</b><small>Record an ingredient purchase</small></a>
                        <a href="?page=reports"><span>▤</span><b>View reports</b><small>Check sales performance</small></a>
                    </div>
                </section>
            </div>

        <?php elseif ($page === 'inventory'): ?>
            <div class="content-grid">
                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Add ingredient</h3>
                            <p>Create a stock record</p>
                        </div>
                    </div>
                    <form method="POST" class="form-grid">
                        <input type="hidden" name="action" value="add_ingredient">
                        <input type="hidden" name="return_page" value="inventory">
                        <label class="wide">Ingredient name<input name="name" required placeholder="e.g. Chicken"></label>
                        <label>Unit<input name="unit" required value="pcs" placeholder="kg, pcs, packs"></label>
                        <label>Starting quantity<input type="number" name="quantity" min="0" step="0.01" required></label>
                        <label class="wide">Reorder level<input type="number" name="reorder_level" min="0" step="0.01" value="5" required></label>
                        <button class="btn primary wide">Save ingredient</button>
                    </form>
                </section>

                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Inventory overview</h3>
                            <p>Stock quantities and reorder levels</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Ingredient</th>
                                    <th>Stock</th>
                                    <th>Reorder at</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ingredients as $i): ?>
                                    <tr>
                                        <td><b><?= e($i['name']) ?></b></td>
                                        <td><?= e(qty($i['quantity'], $i['unit'])) ?> <?= e($i['unit']) ?></td>
                                        <td><?= e(qty($i['reorder_level'], $i['unit'])) ?></td>
                                        <td><?= (float)$i['quantity'] <= (float)$i['reorder_level'] ? '<span class="pill warning">Low stock</span>' : '<span class="pill good">In stock</span>' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$ingredients): ?>
                                    <tr><td colspan="4" class="empty">No ingredients yet. Add your first stock item.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

        <?php elseif ($page === 'menu'): ?>
            <div class="content-grid">
                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Add product</h3>
                            <p>Connect a product to its ingredient usage</p>
                        </div>
                    </div>
                    <form method="POST" class="form-grid">
                        <input type="hidden" name="action" value="add_menu">
                        <input type="hidden" name="return_page" value="menu">
                        <label class="wide">Menu item name<input name="name" required placeholder="e.g. Pork chop w/ Java Rice"></label>
                        <label>Selling price (₱)<input type="number" name="price" min="0.01" step="0.01" required></label>
                        <label>Ingredient
                            <select required name="ingredient_id">
                                <option value="">Choose ingredient</option>
                                <?php foreach ($ingredients as $i): ?>
                                    <option value="<?= $i['id'] ?>"><?= e($i['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="wide">Ingredient used per item<input type="number" name="ingredient_qty" min="0.01" step="0.01" value="1" required></label>
                        <button class="btn primary wide">Save menu item</button>
                    </form>
                    <p class="hint">Simple recipe model: each menu item deducts one linked ingredient. You can extend this to multiple ingredients per recipe later.</p>
                </section>

                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Menu items</h3>
                            <p>Products available for sale</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Price</th>
                                    <th>Ingredient use</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($menuItems as $m): ?>
                                    <tr>
                                        <td><b><?= e($m['name']) ?></b></td>
                                        <td><?= money($m['price']) ?></td>
                                        <td><?= e(qty($m['ingredient_qty'], $m['unit'])) ?> <?= e($m['unit']) ?> <?= e($m['ingredient_name']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$menuItems): ?>
                                    <tr><td colspan="3" class="empty">No menu items yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

        <?php elseif ($page === 'purchases'): ?>
            <div class="content-grid">
                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Record stock purchase</h3>
                            <p>Purchased quantity is added to inventory</p>
                        </div>
                    </div>
                    <form method="POST" class="form-grid">
                        <input type="hidden" name="action" value="purchase">
                        <input type="hidden" name="return_page" value="purchases">
                        <label class="wide">Ingredient
                            <select name="ingredient_id" required>
                                <option value="">Choose ingredient</option>
                                <?php foreach ($ingredients as $i): ?>
                                    <option value="<?= $i['id'] ?>"><?= e($i['name']) ?> (<?= e(qty($i['quantity'], $i['unit'])) ?> <?= e($i['unit']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Quantity<input type="number" name="quantity" min="0.01" step="0.01" required></label>
                        <label>Unit cost (₱)<input type="number" name="unit_cost" min="0" step="0.01" value="0" required></label>
                        <button class="btn primary wide">Save purchase & update stock</button>
                    </form>
                </section>

                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Current stock</h3>
                            <p>Available ingredient quantities</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Ingredient</th>
                                    <th>Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ingredients as $i): ?>
                                    <tr>
                                        <td><?= e($i['name']) ?></td>
                                        <td><?= e(qty($i['quantity'], $i['unit'])) ?> <?= e($i['unit']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

        <?php elseif ($page === 'sales'): ?>
            <div class="content-grid">
                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Record a sale</h3>
                            <p>Ingredient stock is deducted automatically</p>
                        </div>
                    </div>
                    <form method="POST" class="form-grid">
                        <input type="hidden" name="action" value="sale">
                        <input type="hidden" name="return_page" value="sales">
                        <label class="wide">Menu item
                            <select name="menu_item_id" required>
                                <option value="">Choose Product</option>
                                <?php foreach ($menuItems as $m): ?>
                                    <option value="<?= $m['id'] ?>"><?= e($m['name']) ?> · <?= money($m['price']) ?> · Stock: <?= e(qty($m['stock'], $m['unit'])) ?> <?= e($m['unit']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="wide">Quantity sold<input type="number" name="quantity" min="1" step="1" required></label>
                        <button class="btn primary wide">Complete sale</button>
                    </form>
                </section>

                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Recent sales</h3>
                            <p>Latest recorded transactions</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Qty</th>
                                    <th>Total</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice(array_reverse($sales), 0, 12) as $s): ?>
                                    <tr>
                                        <td><?= e($s['item_name']) ?></td>
                                        <td><?= e($s['quantity']) ?></td>
                                        <td><?= money($s['total']) ?></td>
                                        <td><?= e(date('M j, H:i', strtotime($s['sold_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$sales): ?>
                                    <tr><td colspan="4" class="empty">No sales recorded yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

        <?php elseif ($page === 'expenses'): ?>
            <div class="content-grid">
                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Add expense</h3>
                            <p>Record operating costs</p>
                        </div>
                    </div>
                    <form method="POST" class="form-grid">
                        <input type="hidden" name="action" value="expense">
                        <input type="hidden" name="return_page" value="expenses">
                        <label class="wide">Description<input name="description" required placeholder="e.g. Stall rental"></label>
                        <label>Amount (₱)<input type="number" name="amount" min="0.01" step="0.01" required></label>
                        <label>Date<input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required></label>
                        <button class="btn primary wide">Save expense</button>
                    </form>
                </section>

                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Expense records</h3>
                            <p>Recorded business expenses</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenses as $x): ?>
                                    <tr>
                                        <td><?= e($x['description']) ?></td>
                                        <td><?= money($x['amount']) ?></td>
                                        <td><?= e($x['expense_date']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$expenses): ?>
                                    <tr><td colspan="3" class="empty">No expenses recorded.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

        <?php elseif ($page === 'reports'): ?>
            <div class="stats-grid">
                <div class="stat-card"><span>All-time sales</span><strong><?= money($allTimeSales) ?></strong><small>Total recorded revenue</small></div>
                <div class="stat-card"><span>All-time expenses</span><strong><?= money($allTimeExpenses) ?></strong><small>Recorded operating costs</small></div>
                <div class="stat-card"><span>Estimated net profit</span><strong><?= money($allTimeSales - $allTimeExpenses) ?></strong><small>Sales minus recorded expenses</small></div>
                <div class="stat-card"><span>Transactions</span><strong><?= count($sales) ?></strong><small>Recorded sales entries</small></div>
            </div>

            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h3>Sales report by period</h3>
                        <p>Binary search filters sorted sale dates; merge sort ranks products by units sold.</p>
                    </div>
                </div>
                <div class="filter-row">
                    <label>From <input type="date" id="dateFrom" value="<?= date('Y-m-01') ?>"></label>
                    <label>To <input type="date" id="dateTo" value="<?= date('Y-m-d') ?>"></label>
                    <button class="btn primary" type="button" onclick="buildReport()">Generate report</button>
                </div>
                <div class="stats-grid compact">
                    <div class="stat-card"><span>Filtered sales</span><strong id="reportSales">₱0.00</strong></div>
                    <div class="stat-card"><span>Units sold</span><strong id="reportUnits">0</strong></div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Best-selling item</th>
                                <th>Units sold</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody id="reportRows">
                            <tr><td colspan="3" class="empty">Choose a date range and generate a report.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <script>
            window.salesData = <?= json_encode(array_map(function($s) {
                return [
                    'date' => date('Y-m-d', strtotime($s['sold_at'])),
                    'name' => $s['item_name'],
                    'quantity' => (int)$s['quantity'],
                    'total' => (float)$s['total']
                ];
            }, $sales), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            </script>
        <?php endif; ?>

        <footer>4M’s Express <span>Good handling it make your stall growing.</span></footer>
    </main>
</div>
</body>
</html>