<?php
require_once 'connect.php';

// ——— 1. Обработка AJAX ———
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if ($_POST['action'] === 'update_cell') {
        $id = (int)$_POST['id'];
        $field = mysqli_real_escape_string($link, $_POST['field']);
        $value = mysqli_real_escape_string($link, trim($_POST['value']));
        $allowed = ['product_name', 'unit', 'quantity', 'price', 'sale_price', 'location', 'operation_type', 'date'];
        if (!in_array($field, $allowed)) exit(json_encode(['success'=>false]));
        if ($field === 'quantity' || $field === 'price' || $field === 'sale_price') {
            $value = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $value));
            $value = $value === '' ? 'NULL' : "'$value'";
        } else {
            $value = "'" . $value . "'";
        }
        $sql = "UPDATE operations SET `$field` = $value WHERE id = $id";
        exit(json_encode(['success' => (bool)mysqli_query($link, $sql)]));
    }

    if ($_POST['action'] === 'delete_row') {
        $id = (int)$_POST['id'];
        $sql = "DELETE FROM operations WHERE id = $id";
        exit(json_encode(['success' => (bool)mysqli_query($link, $sql)]));
    }

    if ($_POST['action'] === 'write_off') {
        $product = mysqli_real_escape_string($link, $_POST['product']);
        $unit = mysqli_real_escape_string($link, $_POST['unit']);
        $balance = (float)$_POST['balance'];
        $avg_price = (float)$_POST['avg_price'];

        if ($balance <= 0) exit(json_encode(['success'=>false, 'error'=>'Остаток ≤ 0']));

        $sql = "INSERT INTO operations 
                (product_name, unit, quantity, price, sale_price, location, operation_type, date, created_at, period)
                VALUES ('$product', '$unit', $balance, $avg_price, NULL, 'кафе', 'списание', CURDATE(), NOW(), '" . date('Y-m') . "')";
        exit(json_encode(['success' => (bool)mysqli_query($link, $sql)]));
    }
}

// ——— 2. Параметры ———
$period = $_GET['period'] ?? date('Y-m');
$mode = $_GET['mode'] ?? (isset($_GET['period']) ? 'period' : 'today');
$location_filter = $_GET['location'] ?? 'все';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$is_print = isset($_GET['print']) && $_GET['print'] == '1';

// ——— 3. Периоды ———
$periods_sql = "SELECT DISTINCT period FROM operations ORDER BY period DESC";
$periods_result = mysqli_query($link, $periods_sql);
$periods = [];
while ($row = mysqli_fetch_row($periods_result)) {
    $periods[] = $row[0];
}
if (!in_array($period, $periods)) {
    $periods[] = $period;
}

// ——— 4. WHERE ———
// По умолчанию не накладываем условие — добавим период/диапазон ниже.
$where = "1=1";

// Если режим 'period' — фильтруем строго по периоду (архив)
if ($mode === 'period') {
    if ($period !== 'all') {
        $period_esc = mysqli_real_escape_string($link, $period);
        $where .= " AND o.period = '$period_esc'";
    }
} elseif ($mode === 'today') {
    $where .= " AND DATE(o.date) = CURDATE()";
} elseif ($mode === 'week') {
    $where .= " AND o.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($mode === 'month') {
    $where .= " AND o.date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
} elseif ($mode === 'custom' && $date_from && $date_to) {
    $date_from = mysqli_real_escape_string($link, $date_from);
    $date_to = mysqli_real_escape_string($link, $date_to);
    $where .= " AND o.date BETWEEN '$date_from' AND '$date_to'";
}

// Фильтр по месту
if ($location_filter !== 'все') {
    $loc_esc = mysqli_real_escape_string($link, $location_filter);
    $where .= " AND o.location = '$loc_esc'";
}

// ——— 5. Сводка с учётом sale_price ———
$sql_summary = "
    SELECT
        o.product_name, o.unit,
        SUM(CASE WHEN o.operation_type = 'приход' THEN o.quantity ELSE 0 END) AS total_in,
        SUM(CASE WHEN o.operation_type = 'расход' THEN o.quantity ELSE 0 END) AS total_sold,
        SUM(CASE WHEN o.operation_type = 'списание' THEN o.quantity ELSE 0 END) AS total_write_off,
        SUM(CASE WHEN o.operation_type = 'приход' THEN o.quantity ELSE 0 END) - 
        SUM(CASE WHEN o.operation_type IN ('расход', 'списание') THEN o.quantity ELSE 0 END) AS balance,
        
        -- Средняя закупочная цена (только по приходу)
        CASE WHEN SUM(CASE WHEN o.operation_type = 'приход' THEN o.quantity ELSE 0 END) > 0 
            THEN SUM(CASE WHEN o.operation_type = 'приход' THEN o.quantity * o.price ELSE 0 END) / 
                 SUM(CASE WHEN o.operation_type = 'приход' THEN o.quantity ELSE 0 END) 
            ELSE 0 END AS avg_purchase_price,
        
        -- Средняя цена продажи (только по расходу и sale_price; fallback на price)
        CASE WHEN SUM(CASE WHEN o.operation_type = 'расход' THEN o.quantity ELSE 0 END) > 0 
            THEN SUM(CASE WHEN o.operation_type = 'расход' THEN o.quantity * COALESCE(o.sale_price, o.price) ELSE 0 END) / 
                 SUM(CASE WHEN o.operation_type = 'расход' THEN o.quantity ELSE 0 END) 
            ELSE 0 END AS avg_sale_price_new,
        
        -- Затраты (закупка)
        SUM(CASE WHEN o.operation_type = 'приход' THEN o.quantity * o.price ELSE 0 END) AS total_cost,
        
        -- Выручка (по sale_price, если есть, иначе по price)
        SUM(CASE WHEN o.operation_type = 'расход' THEN o.quantity * COALESCE(o.sale_price, o.price) ELSE 0 END) AS revenue,
        
        -- Прибыль = выручка − себестоимость проданного
        SUM(CASE WHEN o.operation_type = 'расход' THEN o.quantity * COALESCE(o.sale_price, o.price) ELSE 0 END) - 
        CASE 
            WHEN SUM(CASE WHEN o.operation_type = 'приход' THEN o.quantity ELSE 0 END) > 0 
            THEN (
                SUM(CASE WHEN o.operation_type = 'расход' THEN o.quantity ELSE 0 END)
            ) * (
                SUM(CASE WHEN o.operation_type = 'приход' THEN o.quantity * o.price ELSE 0 END) / 
                SUM(CASE WHEN o.operation_type = 'приход' THEN o.quantity ELSE 0 END)
            )
            ELSE 0 END AS profit,
        
        -- Остаток в рублях (по средней закупочной)
        (
            SUM(CASE WHEN o.operation_type = 'приход' THEN o.quantity ELSE 0 END) - 
            SUM(CASE WHEN o.operation_type IN ('расход', 'списание') THEN o.quantity ELSE 0 END)
        ) * 
        CASE WHEN SUM(CASE WHEN o.operation_type = 'приход' THEN o.quantity ELSE 0 END) > 0 
            THEN SUM(CASE WHEN o.operation_type = 'приход' THEN o.quantity * o.price ELSE 0 END) / 
                 SUM(CASE WHEN o.operation_type = 'приход' THEN o.quantity ELSE 0 END) 
            ELSE 0 END AS balance_value
    FROM operations o
    WHERE $where
    GROUP BY o.product_name, o.unit
    ORDER BY o.product_name
";
$result_summary = mysqli_query($link, $sql_summary);
$summary_rows = [];
while ($row = mysqli_fetch_assoc($result_summary)) {
    $summary_rows[] = $row;
}

// ——— 5.1. Получение последней цены продажи для товаров без продаж ———
foreach ($summary_rows as &$row) {
    $avg_sale_price = (float)$row['avg_sale_price_new'];
    // Если за период не было продаж, получаем последнюю установленную цену продажи
    if ($avg_sale_price == 0.0) {
        $product_esc = mysqli_real_escape_string($link, $row['product_name']);
        $unit_esc = mysqli_real_escape_string($link, $row['unit']);
        $sql_last_price = "
            SELECT sale_price 
            FROM operations 
            WHERE product_name = '{$product_esc}' 
              AND unit = '{$unit_esc}' 
              AND sale_price IS NOT NULL 
              AND sale_price > 0
            ORDER BY id DESC 
            LIMIT 1
        ";
        $result_last_price = mysqli_query($link, $sql_last_price);
        if ($result_last_price && mysqli_num_rows($result_last_price) > 0) {
            $row_price = mysqli_fetch_assoc($result_last_price);
            $row['avg_sale_price_new'] = (float)$row_price['sale_price'];
        }
    }
}
unset($row);

// ——— 6. Детали ———
$detail_product = $_GET['product'] ?? null;
$detail_rows = [];
if ($detail_product) {
    $product = mysqli_real_escape_string($link, $detail_product);
    $sql_detail = "
        SELECT id, product_name, unit, quantity, price, sale_price, location, operation_type, 
               DATE_FORMAT(date, '%d.%m.%Y') AS date
        FROM operations 
        WHERE product_name = '$product'
        ORDER BY date DESC, id DESC
    ";
    $result_detail = mysqli_query($link, $sql_detail);
    while ($row = mysqli_fetch_assoc($result_detail)) {
        $detail_rows[] = $row;
    }
}

// Серверная печать: если передан print=1 — выдаём упрощённую страницу со всеми строками и авто-печатью
if (isset($_GET['print']) && $_GET['print'] == '1') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Печать — Аналитика</title>";
    echo "<style>body{font-family:Arial,Helvetica,sans-serif;padding:12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:6px;text-align:left}th{background:#f0f0f0}</style>";
    echo "</head><body>";
    echo "<h2>Аналитика — все данные</h2>";
    echo "<table><thead><tr>";
    echo "<th>Товар</th><th>Ед.</th><th>Приход</th><th>Расход</th><th>Остаток</th><th>Закупка</th><th>Продажа</th><th>Затраты</th><th>Выручка</th><th>Прибыль</th>";
    echo "</tr></thead><tbody>";
    foreach ($summary_rows as $r) {
        $balance = (float)$r['balance'];
        $profit = (float)$r['profit'];
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['product_name']) . '</td>';
        echo '<td>' . htmlspecialchars($r['unit']) . '</td>';
        echo '<td style="text-align:right">' . number_format($r['total_in'],2,',',' ') . '</td>';
        echo '<td style="text-align:right">' . number_format($r['total_sold'],2,',',' ') . '</td>';
        echo '<td style="text-align:right">' . number_format($balance,2,',',' ') . '</td>';
        echo '<td style="text-align:right">' . number_format($r['avg_purchase_price'],2,',',' ') . ' ₽</td>';
        echo '<td style="text-align:right">' . number_format($r['avg_sale_price_new'],2,',',' ') . ' ₽</td>';
        echo '<td style="text-align:right">' . number_format($r['total_cost'],2,',',' ') . ' ₽</td>';
        echo '<td style="text-align:right">' . number_format($r['revenue'],2,',',' ') . ' ₽</td>';
        echo '<td style="text-align:right">' . number_format($profit,2,',',' ') . ' ₽</td>';
        echo '</tr>';
    }
    echo "</tbody></table>";
    echo "<script>window.print();</script></body></html>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>📊 Аналитика</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 12px; background: #e0e0e0; color: #212121; }
        .container { max-width: 1800px; margin: 0 auto; }
        .panel { background: #ffffff; border-radius: 8px; box-shadow: 0 3px 10px rgba(0,0,0,0.12); padding: 20px; border: 1px solid #bdbdbd; margin-bottom: 20px; }
        .header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .header-row h2 { margin: 0; color: #37474f; border-bottom: 2px solid #90a4ae; padding-bottom: 6px; }
        .filters { margin-bottom: 15px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .filters label { margin: 0 8px 0 0; color: #546e7a; }
        button { padding: 6px 12px; margin: 4px 4px 4px 0; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 13px; }
        .btn-filter { background: #78909c; color: white; }
        .btn-filter.active { background: #455a64; }
        .btn-back, .btn-archive { background: #546e7a; color: white; }
        .btn-writeoff { background: #d32f2f; color: white; }
        .btn-del { background: #c62828; color: white; padding: 4px 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px 10px; text-align: left; border: 1px solid #cfd8dc; }
        th { background: #e0e0e0; font-weight: 600; color: #37474f; }
        tr:nth-child(even) { background: #f5f7f9; }
        tr:hover { background: #e3f2fd !important; }
        .type-приход { color: #2e7d32; }
        .type-расход { color: #c62828; }
        .type-списание { color: #ad1457; font-weight: bold; }
        .type-ревизия { color: #5d4037; }
        .positive { color: #2e7d32; font-weight: bold; }
        .negative { color: #c62828; font-weight: bold; }
        .zero { color: #607d8b; }
        .highlight { background-color: #fff8e1; }
        .status { font-size: 13px; color: #607d8b; margin-top: 5px; }
        .editable { cursor: pointer; }
        .editable:hover { background-color: #ffe0b2; }
        .editing { background: #fff3e0 !important; }
        .actions { text-align: center; width: 70px; }
        select { padding: 6px 10px; border: 1px solid #90a4ae; border-radius: 4px; background: #f5f7f9; }
        .btn-add { background: #388e3c; color: white; text-decoration: none; padding: 6px 12px; border-radius: 5px; display: inline-block; }
    </style>
</head>
<body>

<div class="container">

<?php if ($detail_product): ?>
<!-- 🔍 Детальный просмотр -->
<div class="panel">
    <div class="header-row">
        <h2>📦 Детали: <?= htmlspecialchars($detail_product) ?> (<?= htmlspecialchars($period) ?>)</h2>
        <div>
            <a href="analytics.php?period=<?= urlencode($period) ?>&location=<?= urlencode($location_filter) ?>&mode=<?= urlencode($mode) ?>" class="btn-back">← Назад</a>
            <button class="btn-filter" onclick="window.open(window.location.pathname + window.location.search + (window.location.search ? '&' : '?') + 'print=1','_blank')">🖨️ Печать</button>
        </div>
    </div>
    <div style="margin-top: 15px; <?= $is_print ? 'max-height:none; overflow:visible;' : 'max-height:65vh; overflow-y:auto;' ?>">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Дата</th>
                    <th>Ед.</th>
                    <th>Кол-во</th>
                    <th>Закупка</th>
                    <th>Продажа</th>
                    <th>Место</th>
                    <th>Тип</th>
                    <th class="actions">🗑</th>
                </tr>
            </thead>
            <tbody id="detail-body">
                <?php foreach ($detail_rows as $row): ?>
                <tr data-id="<?= $row['id'] ?>">
                    <td><?= $row['id'] ?></td>
                    <td class="editable" data-field="date"><?= htmlspecialchars($row['date']) ?></td>
                    <td class="editable" data-field="unit"><?= htmlspecialchars($row['unit']) ?></td>
                    <td class="editable" data-field="quantity" style="text-align:right"><?= htmlspecialchars($row['quantity']) ?></td>
                    <td class="editable" data-field="price" style="text-align:right"><?= number_format($row['price'], 2, ',', ' ') ?> ₽</td>
                    <td class="editable" data-field="sale_price" style="text-align:right">
                        <?= $row['sale_price'] ? number_format($row['sale_price'], 2, ',', ' ') . ' ₽' : '-' ?>
                    </td>
                    <td class="editable" data-field="location"><?= htmlspecialchars($row['location']) ?></td>
                    <td class="editable type-<?= htmlspecialchars($row['operation_type']) ?>" data-field="operation_type">
                        <?= htmlspecialchars($row['operation_type']) ?>
                    </td>
                    <td class="actions">
                        <button class="btn-del" onclick="deleteRow(<?= $row['id'] ?>)">🗑</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- 📊 Сводка -->
    <div class="panel">
    <div class="header-row">
        <h2>📊 Сводка по товарам (<?= htmlspecialchars($period) ?>)</h2>
        <div>
            <a href="cafe.php" class="btn-add">➕ Поступление товара</a>
            <button class="btn-filter" onclick="window.open(window.location.pathname + window.location.search + (window.location.search ? '&' : '?') + 'print=1','_blank')">🖨️ Печать</button>
        </div>
    </div>

    <!-- Выбор периода и места -->
    <div class="filters" style="flex-wrap: nowrap; margin-bottom: 10px;">
        <label>Период:</label>
        <select id="period-select" onchange="setPeriod(this.value)">
            <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>весь период</option>
            <?php foreach ($periods as $p): ?>
            <option value="<?= $p ?>" <?= $p === $period ? 'selected' : '' ?>><?= $p ?></option>
            <?php endforeach; ?>
        </select>

        <span style="margin: 0 15px;">|</span>
        <label>Место:</label>
        <select id="location-select" onchange="setLocation(this.value)">
            <option value="все" <?= $location_filter === 'все' ? 'selected' : '' ?>>все</option>
            <option value="кафе" <?= $location_filter === 'кафе' ? 'selected' : '' ?>>кафе</option>
            <option value="магазин" <?= $location_filter === 'магазин' ? 'selected' : '' ?>>магазин</option>
        </select>

        <button class="btn-archive" onclick="location.href='archive.php'" style="margin-left: 10px;">📁 Архив</button>
    </div>

    <div class="filters">
        <button class="btn-filter <?= $mode==='today'?'active':'' ?>" onclick="setFilter('today')">Сегодня</button>
        <button class="btn-filter <?= $mode==='week'?'active':'' ?>" onclick="setFilter('week')">Неделя</button>
        <button class="btn-filter <?= $mode==='month'?'active':'' ?>" onclick="setFilter('month')">Месяц</button>
        <span style="margin: 0 10px;">|</span>
        <label>С:</label>
        <input type="date" id="date_from" value="<?= htmlspecialchars($date_from ?: date('Y-m-01')) ?>">
        <label>По:</label>
        <input type="date" id="date_to" value="<?= htmlspecialchars($date_to ?: date('Y-m-d')) ?>">
        <button class="btn-filter" onclick="setFilter('custom')">Применить</button>
        <div class="status">Диапазон: <?= 
            ($mode === 'today') ? 'Сегодня' : 
            (($mode === 'week') ? 'Последние 7 дней' : 
            (($mode === 'month') ? 'Последние 30 дней' : 
            ($date_from && $date_to ? "$date_from — $date_to" : 'Весь период')))
        ?></div>
    </div>

    <div style="<?= $is_print ? 'max-height:none; overflow:visible;' : 'max-height:60vh; overflow-y:auto;' ?>">
        <table>
            <thead>
                <tr>
                    <th>Товар</th>
                    <th>Ед.</th>
                    <th>Приход</th>
                    <th>Расход</th>
                    <th>Остаток</th>
                    <th>Закупка</th>
                    <th>Продажа</th>
                    <th>Цена продажи</th>
                    <th>Затраты</th>
                    <th>Выручка</th>
                    <th>Прибыль</th>
                    <th>Рентаб.</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summary_rows as $row): ?>
                <?php
                    $balance = (float)$row['balance'];
                    $profit = (float)$row['profit'];
                    $revenue = (float)$row['revenue'];
                    $avg_sale_price = (float)$row['avg_sale_price_new'];
                    $roi = $revenue > 0 ? ($profit / $revenue * 100) : 0;
                    $balance_class = $balance > 0 ? 'positive' : ($balance < 0 ? 'negative' : 'zero');
                    $profit_class = $profit > 0 ? 'positive' : ($profit < 0 ? 'negative' : 'zero');
                    $roi_class = $roi > 0 ? 'positive' : ($roi < 0 ? 'negative' : 'zero');
                ?>
                <tr class="<?= $balance < 0 ? 'highlight' : '' ?>">
                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                    <td><?= htmlspecialchars($row['unit']) ?></td>
                    <td style="text-align:right"><?= number_format($row['total_in'], 2, ',', ' ') ?></td>
                    <td style="text-align:right"><?= number_format($row['total_sold'], 2, ',', ' ') ?></td>
                    <td style="text-align:right" class="<?= $balance_class ?>"><?= number_format($balance, 2, ',', ' ') ?></td>
                    <td style="text-align:right"><?= number_format($row['avg_purchase_price'], 2, ',', ' ') ?> ₽</td>
                    <td style="text-align:right"><?= number_format($row['avg_sale_price_new'], 2, ',', ' ') ?> ₽</td>
                    <td style="text-align:right"><?= $avg_sale_price > 0 ? number_format($avg_sale_price, 2, ',', ' ') . ' ₽' : '-' ?></td>
                    <td style="text-align:right"><?= number_format($row['total_cost'], 2, ',', ' ') ?> ₽</td>
                    <td style="text-align:right"><?= number_format($row['revenue'], 2, ',', ' ') ?> ₽</td>
                    <td style="text-align:right" class="<?= $profit_class ?>"><?= number_format($profit, 2, ',', ' ') ?> ₽</td>
                    <td style="text-align:right" class="<?= $roi_class ?>"><?= number_format($roi, 1, ',', ' ') ?>%</td>
                    <td>
                        <button class="btn-filter" 
                            onclick="location.href='?period=<?= urlencode($period) ?>&location=<?= urlencode($location_filter) ?>&product=<?= urlencode($row['product_name']) ?>&mode=<?= urlencode($mode) ?>'">
                            🔍
                        </button>
                        <?php if ($balance > 0): ?>
                        <button class="btn-writeoff" title="Списать остаток" 
                            onclick="writeOff(
                                '<?= addslashes(htmlspecialchars_decode($row['product_name'])) ?>',
                                '<?= addslashes($row['unit']) ?>',
                                <?= $balance ?>,
                                <?= $row['avg_purchase_price'] ?>
                            )">🗑</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($summary_rows)): ?>
                <tr><td colspan="13" style="text-align:center; color:#607d8b">Нет данных</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($summary_rows)): ?>
    <?php
        $total_cost = array_sum(array_column($summary_rows, 'total_cost'));
        $total_revenue = array_sum(array_column($summary_rows, 'revenue'));
        $total_profit = array_sum(array_column($summary_rows, 'profit'));
        $total_balance_value = array_sum(array_column($summary_rows, 'balance_value'));
        $total_potential_revenue = 0;
        foreach ($summary_rows as $row) {
            $balance = (float)$row['balance'];
            $avg_sale_price = (float)$row['avg_sale_price_new'];
            if ($balance > 0 && $avg_sale_price > 0) {
                $total_potential_revenue += $balance * $avg_sale_price;
            }
        }
        $total_roi = $total_revenue > 0 ? ($total_profit / $total_revenue * 100) : 0;
        $profit_class = $total_profit > 0 ? 'positive' : ($total_profit < 0 ? 'negative' : 'zero');
        $roi_class = $total_roi > 0 ? 'positive' : ($total_roi < 0 ? 'negative' : 'zero');
    ?>
    <div style="margin-top: 20px; padding: 15px; background: #f5f5f5; border-radius: 6px;">
        <h3>Итого за период: <strong><?= htmlspecialchars($period) ?></strong> (<?= htmlspecialchars($location_filter) ?>)</h3>
        <p><strong>Расход (закупки):</strong> <?= number_format($total_cost, 2, ',', ' ') ?> ₽</p>
        <p><strong>Выручка:</strong> <?= number_format($total_revenue, 2, ',', ' ') ?> ₽</p>
        <p><strong>Прибыль:</strong> <span class="<?= $profit_class ?>"><?= number_format($total_profit, 2, ',', ' ') ?> ₽</span></p>
        <p><strong>Остатки на сумму:</strong> <?= number_format($total_balance_value, 2, ',', ' ') ?> ₽</p>
        <p><strong>Потенциальная выручка от остатков:</strong> <span class="positive"><?= number_format($total_potential_revenue, 2, ',', ' ') ?> ₽</span></p>
        <p><strong>Рентабельность:</strong> <span class="<?= $roi_class ?>"><?= number_format($total_roi, 1, ',', ' ') ?>%</span></p>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>

</div>

<script>
function setPeriod(p) {
    const url = new URL(window.location);
    url.searchParams.set('period', p);
    url.searchParams.set('mode', 'period');
    url.searchParams.set('location', document.getElementById('location-select').value);
    window.location.href = url.toString();
}

function setLocation(loc) {
    const url = new URL(window.location);
    url.searchParams.set('location', loc);
    url.searchParams.set('period', document.getElementById('period-select').value);
    window.location.href = url.toString();
}

function setFilter(mode) {
    const url = new URL(window.location);
    url.searchParams.set('mode', mode);
    url.searchParams.set('period', document.getElementById('period-select').value);
    url.searchParams.set('location', document.getElementById('location-select').value);
    
    if (mode === 'custom') {
        const from = document.getElementById('date_from').value;
        const to = document.getElementById('date_to').value;
        if (!from || !to) { alert('Укажите обе даты'); return; }
        url.searchParams.set('date_from', from);
        url.searchParams.set('date_to', to);
    } else {
        url.searchParams.delete('date_from');
        url.searchParams.delete('date_to');
    }
    window.location.href = url.toString();
}

function writeOff(product, unit, balance, avg_price) {
    if (!confirm(`Списать остаток?\nТовар: ${product}\nОстаток: ${balance} ${unit}\nЦена списания: ${avg_price} ₽`)) return;
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=write_off&product=${encodeURIComponent(product)}&unit=${encodeURIComponent(unit)}&balance=${balance}&avg_price=${avg_price}`
    })
    .then(r => r.json())
    .then(data => { if (data.success) { alert('✅ Остаток списан'); location.reload(); } else { alert('❌ Ошибка'); } });
}

// ——— Редактирование и удаление ———
document.addEventListener('dblclick', function(e) {
    const cell = e.target.closest('.editable');
    if (!cell) return;
    const row = cell.closest('tr');
    const id = row.dataset.id;
    const field = cell.dataset.field;
    const currentValue = cell.textContent.trim().replace(/\s₽$/, '');

    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentValue;
    input.className = 'editing';
    input.style.width = '100%';
    input.style.padding = '4px';
    input.style.border = '1px solid #ff9800';
    cell.innerHTML = '';
    cell.appendChild(input);
    input.focus();

    input.addEventListener('keydown', ev => {
        if (ev.key === 'Enter') {
            saveEdit(id, field, input.value, cell);
        } else if (ev.key === 'Escape') {
            cell.textContent = currentValue;
        }
    });
    input.addEventListener('blur', () => setTimeout(() => {
        if (input.parentElement) cell.textContent = currentValue;
    }, 100));
});

function saveEdit(id, field, value, cell) {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=update_cell&id=${id}&field=${encodeURIComponent(field)}&value=${encodeURIComponent(value)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            let displayValue = value;
            if (field === 'price' || field === 'sale_price') {
                const num = parseFloat(value.replace(',', '.'));
                displayValue = isNaN(num) ? '' : num.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' ₽';
            }
            cell.textContent = displayValue;
            if (field === 'operation_type') {
                cell.className = 'editable type-' + escapeHtml(value);
            }
        } else {
            alert('Ошибка сохранения');
            cell.textContent = value;
        }
    });
}

function deleteRow(id) {
    if (!confirm('Точно удалить?')) return;
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete_row&id=${id}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.querySelector(`tr[data-id="${id}"]`)?.remove();
        } else {
            alert('Ошибка удаления');
        }
    });
}

function escapeHtml(str) {
    return str.replace(/[&<>"']/g, m => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m]));
}
</script>

</body>
</html>