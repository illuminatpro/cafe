<?php
require_once 'connect.php';
// Avoid "Undefined array key 'action'" warnings by ensuring the keys exist
if (!isset($_POST['action'])) $_POST['action'] = null;
if (!isset($_GET['action'])) $_GET['action'] = null;
// Печать — если вызывается с ?print=1 — вернуть всю таблицу без лимита в отдельной печатной странице
if (isset($_GET['print']) && $_GET['print'] == '1') {
    $mode = $_GET['mode'] ?? 'all';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    if ($mode === 'today') {
        $where = "DATE(date) = CURDATE()";
    } elseif ($mode === 'week') {
        $where = "date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($mode === 'month') {
        $where = "date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
    } elseif ($mode === 'custom' && $date_from && $date_to) {
        $df = mysqli_real_escape_string($link, $date_from);
        $dt = mysqli_real_escape_string($link, $date_to);
        $where = "date BETWEEN '$df' AND '$dt'";
    } else {
        $where = "1=1";
    }
    $sql = "SELECT id, product_name, unit, quantity, price, sale_price, location, operation_type, DATE_FORMAT(date, '%d.%m.%Y') AS date FROM operations WHERE $where ORDER BY id DESC";
    $res = mysqli_query($link, $sql);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    // Вывод простой печатной страницы
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Печать операций</title><style>body{font-family:Arial,Helvetica,sans-serif;padding:12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:6px;text-align:left}th{background:#f0f0f0}</style></head><body>";
    echo "<h2>Операции — печать</h2>";
    echo "<table><thead><tr><th>ID</th><th>Дата</th><th>Товар</th><th>Ед.</th><th>Кол-во</th><th>Цена</th><th>Продажа</th><th>Место</th><th>Тип</th></tr></thead><tbody>";
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['id']) . '</td>';
        echo '<td>' . htmlspecialchars($r['date']) . '</td>';
        echo '<td>' . htmlspecialchars($r['product_name']) . '</td>';
        echo '<td>' . htmlspecialchars($r['unit']) . '</td>';
        echo '<td style="text-align:right">' . htmlspecialchars($r['quantity']) . '</td>';
        echo '<td style="text-align:right">' . ($r['price'] !== null ? number_format((float)$r['price'],2,',',' ') . ' ₽' : '') . '</td>';
        echo '<td style="text-align:right">' . ($r['sale_price'] ? number_format((float)$r['sale_price'],2,',',' ') . ' ₽' : '') . '</td>';
        echo '<td>' . htmlspecialchars($r['location']) . '</td>';
        echo '<td>' . htmlspecialchars($r['operation_type']) . '</td>';
        echo '</tr>';
    }
    echo "</tbody></table><script>window.print()</script></body></html>";
    exit;
}
// ——— 1. Подсказки по наименованию ———
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    $query = mysqli_real_escape_string($link, trim($_POST['query']));
    if (strlen($query) < 1) {
        echo json_encode(['success' => true, 'suggestions' => []]);
        exit;
    }
    $sql = "SELECT DISTINCT product_name
            FROM operations
            WHERE product_name LIKE '$query%'
            ORDER BY product_name
            LIMIT 10";
    $result = mysqli_query($link, $sql);
    $suggestions = [];
    while ($row = mysqli_fetch_row($result)) {
        $suggestions[] = $row[0];
    }
    echo json_encode(['success' => true, 'suggestions' => $suggestions]);
    exit;
}
// ——— 2. Получить последнюю цену продажи по товару ———
if ($_POST['action'] === 'get_sale_price') {
    $product = mysqli_real_escape_string($link, $_POST['product']);
    $sql = "SELECT sale_price FROM operations
            WHERE product_name = '$product' AND sale_price IS NOT NULL
            ORDER BY id DESC LIMIT 1";
    $res = mysqli_query($link, $sql);
    $price = $res && mysqli_num_rows($res) ? (float)mysqli_fetch_row($res)[0] : 0;
    echo json_encode(['sale_price' => $price]);
    exit;
}
// ——— 3. Сохранение новой накладной (приход) ———
if ($_POST['action'] === 'save_invoice') {
    $entries = json_decode($_POST['entries'], true);
    if (!$entries) {
        echo json_encode(['success' => false, 'error' => 'Нет данных']);
        exit;
    }
    $success_count = 0;
    $invoice_id = uniqid('INV_');
    foreach ($entries as $e) {
        $product_name = mysqli_real_escape_string($link, trim($e['product_name']));
        $unit = mysqli_real_escape_string($link, trim($e['unit']));
        $quantity = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', trim($e['quantity'])));
        $price = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', trim($e['price'])));
        $sale_price = isset($e['sale_price']) ?
            (str_replace(',', '.', preg_replace('/[^0-9.,]/', '', trim($e['sale_price'])))) : 'NULL';
        $location = mysqli_real_escape_string($link, trim($e['location'] ?? ''));
        $date = mysqli_real_escape_string($link, trim($e['date'] ?: date('Y-m-d')));
        $sale_price_sql = $e['sale_price'] !== '' ? "'$sale_price'" : 'NULL';
        $sql = "INSERT INTO operations
                (product_name, unit, quantity, price, sale_price, location, operation_type, date, created_at, period, invoice_id)
                VALUES ('$product_name', '$unit', '$quantity', '$price', $sale_price_sql, '$location', 'приход', '$date', NOW(), '" . date('Y-m') . "', '$invoice_id')";
        if (mysqli_query($link, $sql)) {
            $success_count++;
        }
    }
    echo json_encode(['success' => true, 'added' => $success_count, 'invoice_id' => $invoice_id]);
    exit;
}
// ——— 4. Добавление строки в существующую накладную (приход) ———
if ($_POST['action'] === 'save_invoice_row') {
    $invoice_id = mysqli_real_escape_string($link, $_POST['invoice_id']);
    $entry = json_decode($_POST['entry'], true);
    if (!$entry || !$invoice_id) {
        echo json_encode(['success' => false, 'error' => 'Нет данных']);
        exit;
    }
    $product_name = mysqli_real_escape_string($link, trim($entry['product_name']));
    $unit = mysqli_real_escape_string($link, trim($entry['unit']));
    $quantity = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', trim($entry['quantity'])));
    $price = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', trim($entry['price'])));
    $sale_price = isset($entry['sale_price']) ?
        (str_replace(',', '.', preg_replace('/[^0-9.,]/', '', trim($entry['sale_price'])))) : 'NULL';
    $location = mysqli_real_escape_string($link, trim($entry['location'] ?? ''));
    $date = mysqli_real_escape_string($link, trim($entry['date'] ?: date('Y-m-d')));
    $sale_price_sql = $entry['sale_price'] !== '' ? "'$sale_price'" : 'NULL';
    $sql = "INSERT INTO operations
            (product_name, unit, quantity, price, sale_price, location, operation_type, date, created_at, period, invoice_id)
            VALUES ('$product_name', '$unit', '$quantity', '$price', $sale_price_sql, '$location', 'приход', '$date', NOW(), '" . date('Y-m') . "', '$invoice_id')";
    echo json_encode(['success' => (bool)mysqli_query($link, $sql)]);
    exit;
}
// ——— 5. Сохранение нового расхода (накладная расхода) ———
if ($_POST['action'] === 'save_expense_invoice') {
    $entries = json_decode($_POST['entries'], true);
    if (!$entries) {
        echo json_encode(['success' => false, 'error' => 'Нет данных']);
        exit;
    }
    $success_count = 0;
    $invoice_id = uniqid('EXP_');
    foreach ($entries as $e) {
        $product_name = mysqli_real_escape_string($link, trim($e['product_name']));
        $unit = mysqli_real_escape_string($link, trim($e['unit']));
        $quantity = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', trim($e['quantity'])));
        $sale_price = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', trim($e['sale_price'])));
        $location = mysqli_real_escape_string($link, trim($e['location'] ?? ''));
        $date = mysqli_real_escape_string($link, trim($e['date'] ?: date('Y-m-d')));
        $sql = "INSERT INTO operations
                (product_name, unit, quantity, price, sale_price, location, operation_type, date, created_at, period, invoice_id)
                VALUES ('$product_name', '$unit', '$quantity', NULL, '$sale_price', '$location', 'расход', '$date', NOW(), '" . date('Y-m') . "', '$invoice_id')";
        if (mysqli_query($link, $sql)) {
            $success_count++;
        }
    }
    echo json_encode(['success' => true, 'added' => $success_count, 'invoice_id' => $invoice_id]);
    exit;
}
// ——— 6. Добавление строки в существующий расход ———
if ($_POST['action'] === 'save_expense_row') {
    $invoice_id = mysqli_real_escape_string($link, $_POST['invoice_id']);
    $entry = json_decode($_POST['entry'], true);
    if (!$entry || !$invoice_id) {
        echo json_encode(['success' => false, 'error' => 'Нет данных']);
        exit;
    }
    $product_name = mysqli_real_escape_string($link, trim($entry['product_name']));
    $unit = mysqli_real_escape_string($link, trim($entry['unit']));
    $quantity = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', trim($entry['quantity'])));
    $sale_price = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', trim($entry['sale_price'])));
    $location = mysqli_real_escape_string($link, trim($entry['location'] ?? ''));
    $date = mysqli_real_escape_string($link, trim($entry['date'] ?: date('Y-m-d')));
    $sql = "INSERT INTO operations
            (product_name, unit, quantity, price, sale_price, location, operation_type, date, created_at, period, invoice_id)
            VALUES ('$product_name', '$unit', '$quantity', NULL, '$sale_price', '$location', 'расход', '$date', NOW(), '" . date('Y-m') . "', '$invoice_id')";
    echo json_encode(['success' => (bool)mysqli_query($link, $sql)]);
    exit;
}
// ——— 7. Загрузка накладной по ID (и приход, и расход) ———
if ($_GET['action'] === 'load_invoice' && !empty($_GET['invoice_id'])) {
    $invoice_id = mysqli_real_escape_string($link, $_GET['invoice_id']);
    $sql = "SELECT id, product_name, unit, quantity, price, sale_price, location, operation_type, date,
                   DATE_FORMAT(date, '%Y-%m-%d') AS date_str
            FROM operations
            WHERE invoice_id = '$invoice_id'
            ORDER BY id ASC";
    $result = mysqli_query($link, $sql);
    $rows = [];
    $first_row = mysqli_fetch_assoc($result);
    if (!$first_row) {
        echo json_encode(['success' => false, 'error' => 'Накладная не найдена']);
        exit;
    }
    $invoice_date = $first_row['date'];
    $invoice_location = $first_row['location'];
    $rows[] = $first_row;
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    echo json_encode([
        'success' => true,
        'rows' => $rows,
        'date' => $invoice_date,
        'location' => $invoice_location
    ]);
    exit;
}
// ——— 8. Массовое добавление (без накладных) ———
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $success_count = 0;
    $max = max(
        count($_POST['product_name'] ?? []),
        count($_POST['quantity'] ?? []),
        count($_POST['price'] ?? [])
    );
    for ($i = 0; $i < $max; $i++) {
        $product_name   = trim($_POST['product_name'][$i] ?? '');
        $quantity_raw   = trim($_POST['quantity'][$i] ?? '');
        $price_raw      = trim($_POST['price'][$i] ?? '');
        $unit           = trim($_POST['unit'][$i] ?? '');
        $location       = trim($_POST['location'][$i] ?? '');
        $operation_type = trim($_POST['operation_type'][$i] ?? '');
        $date           = trim($_POST['date'][$i] ?? '');
        if ($product_name === '' || $quantity_raw === '' || $price_raw === '') continue;
        $quantity = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $quantity_raw));
        $price    = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $price_raw));
        $product_name   = mysqli_real_escape_string($link, $product_name);
        $unit           = mysqli_real_escape_string($link, $unit);
        $location       = mysqli_real_escape_string($link, $location);
        $operation_type =	mysqli_real_escape_string($link, $operation_type);
        $date           = mysqli_real_escape_string($link, $date);
        $sql = "INSERT INTO operations
                (product_name, unit, quantity, price, location, operation_type, date, created_at, period)
                VALUES ('$product_name', '$unit', '$quantity', '$price', '$location', '$operation_type', '$date', NOW(), '" . date('Y-m') . "')";
        if (mysqli_query($link, $sql)) {
            $success_count++;
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => "✅ Добавлено: $success_count",
        'added' => $success_count
    ]);
    exit;
}
// ——— 9. Обработка AJAX ———
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    if ($_POST['action'] === 'update_cell') {
        $id = (int)$_POST['id'];
        $field = mysqli_real_escape_string($link, $_POST['field']);
        $value = mysqli_real_escape_string($link, trim($_POST['value']));
        $allowed = ['product_name', 'unit', 'quantity', 'price', 'sale_price', 'location', 'operation_type', 'date'];
        if (!in_array($field, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Поле запрещено']);
            exit;
        }
        // Числовые поля нормализуем, все остальные (включая дату) — как текст
        if ($field === 'quantity' || $field === 'price' || $field === 'sale_price') {
            $value = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $value));
            $value = $value === '' ? 'NULL' : "'$value'";
        } else {
            $value = "'" . $value . "'";
        }
        $sql = "UPDATE operations SET `$field` = $value WHERE id = $id";
        echo json_encode(['success' => (bool)mysqli_query($link, $sql)]);
        exit;
    }

    if ($_POST['action'] === 'delete_row') {
        $id = (int)$_POST['id'];
        $sql = "DELETE FROM operations WHERE id = $id";
        echo json_encode(['success' => (bool)mysqli_query($link, $sql)]);
        exit;
    }

    if ($_POST['action'] === 'load_data') {
        $mode = $_POST['mode'] ?? 'today';
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        $where = '';
        if ($mode === 'today') {
            $where = "DATE(date) = CURDATE()";
        } elseif ($mode === 'week') {
            $where = "date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($mode === 'month') {
            $where = "date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
        } elseif ($mode === 'custom' && $date_from && $date_to) {
            $date_from = mysqli_real_escape_string($link, $date_from);
            $date_to = mysqli_real_escape_string($link, $date_to);
            $where = "date BETWEEN '$date_from' AND '$date_to'";
        } else {
            $where = "1=1";
        }
        $sql = "SELECT id, product_name, unit, quantity, price, sale_price, location, operation_type, invoice_id,
                       DATE_FORMAT(date, '%d.%m.%Y') AS date,
                       DATE_FORMAT(date, '%Y-%m-%d') AS date_edit
                FROM operations
                WHERE $where
                ORDER BY id DESC
                LIMIT 200";
        $result = mysqli_query($link, $sql);
        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        echo json_encode(['success' => true, 'rows' => $rows]);
        exit;
    }
}

// ——— 10. Данные за сегодня ———
$where_today = "DATE(date) = CURDATE()";
$sql_today = "SELECT id, product_name, unit, quantity, price, sale_price, location, operation_type, invoice_id,
                     DATE_FORMAT(date, '%d.%m.%Y') AS date,
                     DATE_FORMAT(date, '%Y-%m-%d') AS date_edit
              FROM operations
              WHERE DATE(date) = CURDATE()
              ORDER BY id DESC
              LIMIT 200";
$result_today = mysqli_query($link, $sql_today);
$initial_rows = [];
while ($row = mysqli_fetch_assoc($result_today)) {
    $initial_rows[] = $row;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Учёт кафе</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 12px; background: #e0e0e0; color: #212121; }
        .container { display: flex; gap: 20px; max-width: 1800px; margin: 0 auto; }
        .panel { background: #ffffff; border-radius: 8px; box-shadow: 0 3px 10px rgba(0,0,0,0.12); padding: 20px; border: 1px solid #bdbdbd; }
        .form-panel { flex: 1; max-width: 600px; }
        .table-panel { flex: 2; }
        h2 { margin-top: 0; color: #37474f; border-bottom: 2px solid #90a4ae; padding-bottom: 6px; }
        .form-group { margin: 10px 0; display: flex; align-items: center; gap: 12px; }
        label { width: 120px; text-align: right; font-weight: 600; color: #546e7a; }
        input, select { padding: 8px 10px; border: 1px solid #90a4ae; border-radius: 4px; background: #f5f7f9; color: #263238; }
        input:focus, select:focus { outline: none; border-color: #455a64; background: #fff; }
        .entry { border: 1px solid #cfd8dc; padding: 16px; margin-bottom: 16px; border-radius: 6px; background: #f8fafc; }
        .qty-price { display: flex; gap: 10px; align-items: center; }
        .qty, .price { width: 60px; text-align: center; }
        .product-name { width: 200px; }
        button { padding: 8px 16px; margin: 4px 6px 4px 0; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
        button:hover { opacity: 0.9; transform: translateY(-1px); }
        button:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .btn-add { background: #546e7a; color: white; }
        .btn-save { background: #388e3c; color: white; }
        .btn-del { background: #d32f2f; color: white; padding: 5px 10px; }
        .btn-filter { background: #78909c; color: white; }
        .btn-filter.active { background: #455a64; }
        .filters { margin-bottom: 15px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .filters label { margin: 0 8px 0 0; color: #546e7a; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px 12px; text-align: left; border: 1px solid #cfd8dc; }
        th { background: #e0e0e0; font-weight: 600; color: #37474f; text-align: center; }
        tr:nth-child(even) { background: #f5f7f9; }
        tr:hover { background: #e3f2fd !important; }
        .type-приход { color: #2e7d32; font-weight: bold; }
        .type-расход { color: #c62828; font-weight: bold; }
        .type-списание { color: #ad1457; font-weight: bold; }
        .type-ревизия { color: #5d4037; font-weight: bold; }
        .actions { text-align: center; width: 80px; }
        .editable { cursor: pointer; }
        .editable:hover { background-color: #ffe0b2; }
        .editing { background: #fff3e0 !important; }
        .status { font-size: 13px; color: #607d8b; margin-top: 5px; }

        /* Автоподсказки */
        .autocomplete { position: relative; display: inline-block; width: 200px; }
        .autocomplete-items {
            position: absolute; border: 1px solid #90a4ae; border-top: none;
            background: white; z-index: 99; top: 100%; left: 0; right: 0;
            max-height: 200px; overflow-y: auto; border-radius: 0 0 4px 4px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        .autocomplete-items div {
            padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee;
        }
        .autocomplete-items div:hover, .autocomplete-active { background: #e3f2fd; }
        .autocomplete-active { background: #2196f3 !important; color: white; }

        /* Модальные окна */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal > div {
            background: white; margin: 30px auto; padding: 20px;
            width: 1000px; max-width: 95%; max-height: 90vh; overflow: hidden;
            border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            display: flex; flex-direction: column;
            position: relative;
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;
            cursor: move;
        }
        .modal-title {
            font-size: 18px; font-weight: bold; color: #37474f;
        }
        .close-btn {
            background: none; border: none; font-size: 24px; color: #666; cursor: pointer;
            width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;
            border-radius: 4px;
        }
        .close-btn:hover { color: #000; background: #f0f0f0; }

        .table-wrapper { flex: 1; overflow-y: auto; margin-bottom: 15px; border: 1px solid #ccc; }
        table.data-table { width: 100%; border-collapse: collapse; }
        table.data-table th, table.data-table td { padding: 8px 10px; border: 1px solid #ddd; }
        table.data-table th { background: #f0f0f0; }
        .actions-row { text-align: right; margin-top: 10px; }

        .totals-row {
            display: grid; grid-template-columns: 1fr 1fr 1fr;
            gap: 15px; font-weight: bold; background: #e8f5e8; padding: 10px 0;
        }

        /* Стили для расхода */
        .expense-modal .modal-header {
            background: #ffebee;
            border-bottom: 2px solid #ef5350;
            padding: 10px 20px;
        }
        .expense-modal .modal-title { color: #d32f2f; }
        .expense-modal .btn-save { background: #d32f2f; }
        .expense-modal .btn-del { background: #b71c1c; }
        .expense-modal .totals-row {
            background: #ffcdd2;
        }
        .expense-modal .totals-row span {
            color: #d32f2f;
        }

        /* Размеры для расхода */
        .expense-modal .table-wrapper {
            min-width: 800px;
        }
        .expense-modal .table-wrapper table.data-table {
            width: 100%;
        }
        .modal > div {
            box-sizing: border-box;
        }

        /* Индикатор прогресса */
        .progress-container {
            margin: 15px 0;
            display: none;
        }
        .progress-container.active {
            display: block;
        }
        .progress-bar {
            width: 100%;
            height: 24px;
            background: #e0e0e0;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4caf50, #8bc34a);
            width: 0%;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }
        .progress-text {
            margin-top: 5px;
            text-align: center;
            font-size: 13px;
            color: #546e7a;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="panel form-panel">
        <h2>➕ Ввод операций Кафе</h2>
        <div style="margin-bottom: 15px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
            <button class="btn-filter" onclick="location.href='analytics.php'">📊 Аналитика</button>
            <button class="btn-filter" onclick="location.href='analytic2.php'">📊 Остатки по неделям</button>
            <a href="https://kubanlogist.ru/analytic2.php" target="_blank" style="font-size:13px; color:#37474f; text-decoration:none;">
                версия на сайте
            </a>
        </div>
        <form id="main-form" method="POST">
            <div id="entries">
                <div class="entry">
                    <h3>Запись 1</h3>
                    <div class="form-group">
                        <label>Наименование:</label>
                        <div class="autocomplete">
                            <input type="text" name="product_name[]" required class="product-name autocomplete-input" placeholder="пирожки" autocomplete="off">
                            <div class="autocomplete-items"></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Ед. изм.:</label>
                        <select name="unit[]" required>
                            <option value="шт">шт</option>
                            <option value="кг">кг</option>
                            <option value="лотки">лотки</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Кол-во / Цена:</label>
                        <div class="qty-price">
                            <input type="text" name="quantity[]" required class="qty" placeholder="10">
                            <input type="text" name="price[]" required class="price" placeholder="20">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Место:</label>
                        <select name="location[]" required>
                            <option value="кафе">кафе</option>
                            <option value="магазин">магазин</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Тип:</label>
                        <select name="operation_type[]" required>
                            <option value="приход">приход</option>
                            <option value="расход">расход</option>
                            <option value="списание">списание</option>
                            <option value="ревизия">ревизия</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Дата:</label>
                        <input type="date" name="date[]" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
            </div>
            <button type="button" onclick="addEntry()" class="btn-add">➕ Добавить ещё</button>
            <button type="button" class="btn-add" onclick="openInvoice()">➕ Накладная (приход)</button>
            <button type="button" class="btn-add" style="background:#d32f2f;" onclick="openExpenseInvoice()">➖ Расход</button>
            <button type="submit" class="btn-save">✅ Сохранить</button>
        </form>
    </div>

    <div class="panel table-panel">
        <h2>🗃️ Операции</h2>
        <div class="filters">
            <button class="btn-filter active" onclick="loadData('today')">Сегодня</button>
            <button class="btn-filter" onclick="loadData('week')">Неделя</button>
            <button class="btn-filter" onclick="loadData('month')">Месяц</button>
            <span style="margin: 0 10px;">|</span>
            <label>С:</label>
            <input type="date" id="date_from" value="<?= date('Y-m-01') ?>">
            <label>По:</label>
            <input type="date" id="date_to" value="<?= date('Y-m-d') ?>">
            <button class="btn-filter" onclick="loadData('custom')">Применить</button>
            <button class="btn-filter" onclick="printCurrent()">🖨️ Печать</button>
            <div class="status" id="status">Загружено: <?= count($initial_rows) ?> записей (за сегодня)</div>
        </div>

        <div style="max-height: 65vh; overflow-y: auto;">
            <table id="data-table" class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Дата</th>
                        <th>Товар</th>
                        <th>Ед.</th>
                        <th>Кол-во</th>
                        <th>Цена</th>
                        <th>Продажа</th>
                        <th>Место</th>
                        <th>Тип</th>
                        <th></th>
                        <th class="actions">🗑</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <?php foreach ($initial_rows as $row): ?>
                    <tr data-id="<?= $row['id'] ?>">
                        <td><?= $row['id'] ?></td>
                        <td class="editable" data-field="date" data-edit="<?= $row['date_edit'] ?>"><?= htmlspecialchars($row['date']) ?></td>
                        <td class="editable" data-field="product_name"><?= htmlspecialchars($row['product_name']) ?></td>
                        <td class="editable" data-field="unit"><?= htmlspecialchars($row['unit']) ?></td>
                        <td class="editable" data-field="quantity" style="text-align:right"><?= htmlspecialchars($row['quantity']) ?></td>
                        <td class="editable" data-field="price" style="text-align:right"><?= number_format($row['price'], 2, ',', ' ') ?> ₽</td>
                        <td class="editable" data-field="sale_price" style="text-align:right"><?= $row['sale_price'] ? number_format($row['sale_price'], 2, ',', ' ') . ' ₽' : '' ?></td>
                        <td class="editable" data-field="location"><?= htmlspecialchars($row['location']) ?></td>
                        <td class="editable type-<?= htmlspecialchars($row['operation_type']) ?>" data-field="operation_type"><?= htmlspecialchars($row['operation_type']) ?></td>
                        <td>
                            <?php if (!empty($row['invoice_id'])): ?>
                                <button class="btn-filter" style="padding:3px 6px;"
                                    onclick="editInvoiceOrExpense('<?= $row['invoice_id'] ?>')">📄</button>
                            <?php endif; ?>
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
</div>

<!-- Модальное окно: Новая накладная (приход) -->
<div id="invoice-modal" class="modal">
    <div>
        <div class="modal-header" onmousedown="startDrag(event, this.parentNode)">
            <h3 class="modal-title">📦 Новая накладная (приход)</h3>
            <button onclick="closeInvoice()" class="close-btn">&times;</button>
        </div>
        <div class="form-group">
            <label>Место:</label>
            <select id="invoice-location" style="width:auto;">
                <option value="кафе">кафе</option>
                <option value="магазин">магазин</option>
            </select>
            <label style="margin-left:20px;">Дата:</label>
            <input type="date" id="invoice-date" value="<?= date('Y-m-d') ?>" style="width:auto;">
            <button type="button" class="btn-add" style="margin-left:15px;" onclick="addInvoiceRow()">➕ Добавить строку</button>
        </div>
        <div class="table-wrapper">
            <table id="invoice-table" class="data-table">
                <thead>
                    <tr>
                        <th>Товар</th>
                        <th>Ед.</th>
                        <th>Кол-во</th>
                        <th>Цена закупки</th>
                        <th>Цена продажи</th>
                        <th>Сумма</th>
                        <th style="width:40px;"></th>
                    </tr>
                </thead>
                <tbody id="invoice-tbody">
                </tbody>
            </table>
        </div>
        <div class="totals-row">
            <div>💰 Итого закупка: <span id="invoice-total-purchase">0,00 ₽</span></div>
            <div>🏷️ Итого продажа: <span id="invoice-total-sale">0,00 ₽</span></div>
            <div>📊 Итого: <span id="invoice-total">0,00 ₽</span></div>
        </div>
        <div class="actions-row">
            <button class="btn-del" onclick="closeInvoice()">❌ Отмена</button>
            <button class="btn-save" onclick="saveInvoice()">✅ Сохранить</button>
        </div>
    </div>
</div>

<!-- Модальное окно: Редактирование накладной (приход) -->
<div id="edit-invoice-modal" class="modal">
    <div>
        <div class="modal-header" onmousedown="startDrag(event, this.parentNode)">
            <h3 class="modal-title">✏️ Редактирование накладной <span id="edit-invoice-id"></span></h3>
            <button onclick="closeEditInvoice()" class="close-btn">&times;</button>
        </div>
        <div class="form-group">
            <label>Место:</label>
            <select id="edit-invoice-location" style="width:auto;">
                <option value="кафе">кафе</option>
                <option value="магазин">магазин</option>
            </select>
            <label style="margin-left:20px;">Дата:</label>
            <input type="date" id="edit-invoice-date" value="<?= date('Y-m-d') ?>" style="width:auto;">
            <button type="button" class="btn-add" style="margin-left:15px;" onclick="addEditInvoiceRow()">➕ Добавить строку</button>
        </div>
        <div class="table-wrapper">
            <table id="edit-invoice-table" class="data-table">
                <thead>
                    <tr>
                        <th>Товар</th>
                        <th>Ед.</th>
                        <th>Кол-во</th>
                        <th>Цена закупки</th>
                        <th>Цена продажи</th>
                        <th>Сумма</th>
                        <th style="width:40px;"></th>
                    </tr>
                </thead>
                <tbody id="edit-invoice-tbody">
                </tbody>
            </table>
        </div>
        <div class="totals-row">
            <div>💰 Итого закупка: <span id="edit-invoice-total-purchase">0,00 ₽</span></div>
            <div>🏷️ Итого продажа: <span id="edit-invoice-total-sale">0,00 ₽</span></div>
            <div>📊 Итого: <span id="edit-invoice-total">0,00 ₽</span></div>
        </div>
        <div class="progress-container" id="edit-invoice-progress">
            <div class="progress-bar">
                <div class="progress-fill" id="edit-invoice-progress-fill">0%</div>
            </div>
            <div class="progress-text" id="edit-invoice-progress-text">Ожидание...</div>
        </div>
        <div class="actions-row">
            <button class="btn-del" onclick="closeEditInvoice()">❌ Отмена</button>
            <button class="btn-save" onclick="saveEditInvoice()" id="edit-invoice-save-btn">✅ Сохранить</button>
        </div>
    </div>
</div>

<!-- Модальное окно: Новая накладная (расход) -->
<div id="expense-modal" class="modal expense-modal">
    <div>
        <div class="modal-header" onmousedown="startDrag(event, this.parentNode)">
            <h3 class="modal-title">📦 Новая накладная (расход)</h3>
            <button onclick="closeExpenseInvoice()" class="close-btn">&times;</button>
        </div>
        <div class="form-group">
            <label>Место:</label>
            <select id="expense-location" style="width:auto;">
                <option value="кафе">кафе</option>
                <option value="магазин">магазин</option>
            </select>
            <label style="margin-left:20px;">Дата:</label>
            <input type="date" id="expense-date" value="<?= date('Y-m-d') ?>" style="width:auto;">
            <button type="button" class="btn-add" style="margin-left:15px;" onclick="addExpenseRow()">➕ Добавить строку</button>
        </div>
        <div class="table-wrapper">
            <table id="expense-table" class="data-table">
                <thead>
                    <tr>
                        <th>Товар</th>
                        <th>Ед.</th>
                        <th>Кол-во</th>
                        <th>Цена Продажи</th>
                        <th>Сумма</th>
                        <th style="width:40px;"></th>
                    </tr>
                </thead>
                <tbody id="expense-tbody">
                </tbody>
            </table>
        </div>
        <div class="totals-row">
            <div>💰 Общая сумма расхода: <span id="expense-total">0,00 ₽</span></div>
        </div>
        <div class="actions-row">
            <button class="btn-del" onclick="closeExpenseInvoice()">❌ Отмена</button>
            <button class="btn-save" onclick="saveExpenseInvoice()">✅ Сохранить</button>
        </div>
    </div>
</div>

<!-- Модальное окно: Редактирование накладной (расход) -->
<div id="edit-expense-modal" class="modal expense-modal">
    <div>
        <div class="modal-header" onmousedown="startDrag(event, this.parentNode)">
            <h3 class="modal-title">✏️ Редактирование накладной <span id="edit-expense-id"></span></h3>
            <button onclick="closeEditExpense()" class="close-btn">&times;</button>
        </div>
        <div class="form-group">
            <label>Место:</label>
            <select id="edit-expense-location" style="width:auto;">
                <option value="кафе">кафе</option>
                <option value="магазин">магазин</option>
            </select>
            <label style="margin-left:20px;">Дата:</label>
            <input type="date" id="edit-expense-date" value="<?= date('Y-m-d') ?>" style="width:auto;">
            <button type="button" class="btn-add" style="margin-left:15px;" onclick="addEditExpenseRow()">➕ Добавить строку</button>
        </div>
        <div class="table-wrapper">
            <table id="edit-expense-table" class="data-table">
                <thead>
                    <tr>
                        <th>Товар</th>
                        <th>Ед.</th>
                        <th>Кол-во</th>
                        <th>Цена Продажи</th>
                        <th>Сумма</th>
                        <th style="width:40px;"></th>
                    </tr>
                </thead>
                <tbody id="edit-expense-tbody">
                </tbody>
            </table>
        </div>
        <div class="totals-row">
            <div>💰 Общая сумма расхода: <span id="edit-expense-total">0,00 ₽</span></div>
        </div>
        <div class="progress-container" id="edit-expense-progress">
            <div class="progress-bar">
                <div class="progress-fill" id="edit-expense-progress-fill">0%</div>
            </div>
            <div class="progress-text" id="edit-expense-progress-text">Ожидание...</div>
        </div>
        <div class="actions-row">
            <button class="btn-del" onclick="closeEditExpense()">❌ Отмена</button>
            <button class="btn-save" onclick="saveEditExpense()" id="edit-expense-save-btn">✅ Сохранить</button>
        </div>
    </div>
</div>

<script>
let count = 1;

// —— DRAG ——
let dragOffsetX = 0, dragOffsetY = 0;
function startDrag(e, modal) {
    e.preventDefault();
    const rect = modal.getBoundingClientRect();
    // Фиксируем текущие размеры перед перетаскиванием, чтобы попап не "сжимался"
    modal.style.position = 'absolute';
    modal.style.width = rect.width + 'px';
    modal.style.height = rect.height + 'px';
    modal.style.maxWidth = 'none';
    const offsetX = e.clientX - rect.left;
    const offsetY = e.clientY - rect.top;
    const move = (ev) => {
        modal.style.left = (ev.clientX - offsetX) + 'px';
        modal.style.top = (ev.clientY - offsetY) + 'px';
    };
    const stop = () => {
        document.removeEventListener('mousemove', move);
        document.removeEventListener('mouseup', stop);
    };
    document.addEventListener('mousemove', move);
    document.addEventListener('mouseup', stop);
}

// —— UTILS ——
function escapeHtml(str) {
    return str.replace(/[&<>"']/g, m => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m]));
}
function formatPrice(val) {
    const num = parseFloat(val);
    return isNaN(num) ? val : num.toLocaleString('ru-RU', { minimumFractionDigits: 2, useGrouping: true }) + ' ₽';
}

// Безопасно парсим ответ сервера как JSON — логируем и возвращаем объект ошибки при невалидном JSON
function parseJSONResponse(response) {
    return response.text().then(txt => {
        try {
            return JSON.parse(txt);
        } catch (e) {
            console.error('Invalid JSON response from server:', txt);
            return { success: false, error: 'Invalid JSON response from server', _raw: txt };
        }
    });
}

// —— AUTOCOMPLETE (уже исправлен) ——
function initAutocomplete(inp) {
    let currentFocus = -1;
    inp.addEventListener("input", function(e) {
        const val = this.value.trim();
        closeAllLists();
        if (!val) return;
        currentFocus = -1;
        const list = document.createElement("DIV");
        list.className = "autocomplete-items";
        this.parentNode.appendChild(list);
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=suggest&query=${encodeURIComponent(val)}`
        }).then(parseJSONResponse).then(data => {
            if (data.success && data.suggestions.length > 0) {
                data.suggestions.forEach(item => {
                    const div = document.createElement("DIV");
                    const safe = item.replace(/'/g, "&#39;").replace(/"/g, "&quot;");
                    div.innerHTML = `<strong>${item.substring(0, val.length)}</strong>${item.substring(val.length)}<input type='hidden' value='${safe}'>`;
                    div.addEventListener("click", () => {
                        inp.value = item;
                        closeAllLists();
                        inp.focus();
                        fetch('', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=get_sale_price&product=${encodeURIComponent(item)}`
                            }).then(parseJSONResponse).then(data => {
                            if (data.sale_price > 0) {
                                const row = inp.closest('tr') || inp.closest('.entry');
                                if (!row) return;

                                // 1) Расходные накладные: sale_price -> поле "Цена продажи"
                                if (row.closest('#expense-tbody, #edit-expense-tbody')) {
                                    const salePriceInput = row.querySelector('.sale_price');
                                    if (salePriceInput) salePriceInput.value = data.sale_price;
                                    if (row.closest('#expense-tbody')) updateExpenseTotal();
                                    if (row.closest('#edit-expense-tbody')) updateEditExpenseTotal();
                                    return;
                                }

                                // 2) Приходные накладные: sale_price -> поле "Цена продажи" (НЕ в "Цена закупки")
                                const invoiceSalePriceInput = row.querySelector('.sale_price');
                                if (invoiceSalePriceInput) {
                                    invoiceSalePriceInput.value = data.sale_price;
                                    if (row.closest('#invoice-tbody')) updateInvoiceTotal();
                                    if (row.closest('#edit-invoice-tbody')) updateEditInvoiceTotal();
                                    return;
                                }

                                // 3) Основная форма (без отдельного поля sale_price): оставляем поведение как раньше
                                const priceInput = row.querySelector('.price');
                                if (priceInput) priceInput.value = data.sale_price;
                            }
                        });
                    });
                    list.appendChild(div);
                });
            } else {
                const div = document.createElement("DIV");
                div.textContent = "не найдено";
                div.style.cssText = "padding:8px 12px;color:#999;font-size:13px;";
                list.appendChild(div);
            }
        });
    });

    inp.addEventListener("keydown", function(e) {
        const x = this.parentNode.querySelector(".autocomplete-items");
        const items = x ? x.getElementsByTagName("div") : [];
        if (e.keyCode === 9 && x) { e.preventDefault(); if (currentFocus > -1) items[currentFocus]?.click(); closeAllLists(); return; }
        if (!x) return;
        if (e.keyCode == 40) { currentFocus++; addActive(items); }
        else if (e.keyCode == 38) { currentFocus--; addActive(items); }
        else if (e.keyCode == 13) { e.preventDefault(); if (currentFocus > -1) items[currentFocus]?.click(); }
        else if (e.keyCode == 27) { closeAllLists(); }
    });
}
function addActive(x) {
    if (!x) return; removeActive(x);
    if (currentFocus >= x.length) currentFocus = 0;
    if (currentFocus < 0) currentFocus = x.length - 1;
    x[currentFocus].classList.add("autocomplete-active");
}
function removeActive(x) {
    for (let i = 0; i < x.length; i++) x[i].classList.remove("autocomplete-active");
}
function closeAllLists() {
    document.querySelectorAll(".autocomplete-items").forEach(el => el.parentNode?.removeChild(el));
}
document.addEventListener("click", closeAllLists);

function initAutocompleteForNewInputs() {
    document.querySelectorAll('.autocomplete-input').forEach(input => {
        if (!input.dataset.autocomplete) {
            input.dataset.autocomplete = 'true';
            initAutocomplete(input);
        }
    });
}
document.addEventListener("DOMContentLoaded", initAutocompleteForNewInputs);

// —— ENTRY ——
function addEntry() {
    count++;
    const div = document.getElementById('entries');
    const html = `<div class="entry"><h3>Запись ${count}</h3>
<div class="form-group">
<label>Наименование:</label>
<div class="autocomplete">
<input type="text" name="product_name[]" required class="product-name autocomplete-input" placeholder="пирожки" autocomplete="off">
<div class="autocomplete-items"></div>
</div>
</div>
<div class="form-group">
<label>Ед. изм.:</label>
<select name="unit[]" required>
<option value="шт">шт</option>
<option value="кг">кг</option>
<option value="лотки">лотки</option>
</select>
</div>
<div class="form-group">
<label>Кол-во / Цена:</label>
<div class="qty-price">
<input type="text" name="quantity[]" required class="qty" placeholder="10">
<input type="text" name="price[]" required class="price" placeholder="20">
</div>
</div>
<div class="form-group">
<label>Место:</label>
<select name="location[]" required>
<option value="кафе">кафе</option>
<option value="магазин">магазин</option>
</select>
</div>
<div class="form-group">
<label>Тип:</label>
<select name="operation_type[]" required>
<option value="приход">приход</option>
<option value="расход">расход</option>
<option value="списание">списание</option>
<option value="ревизия">ревизия</option>
</select>
</div>
<div class="form-group">
<label>Дата:</label>
<input type="date" name="date[]" value="<?= date('Y-m-d') ?>" required>
</div>
</div>`;
    div.insertAdjacentHTML('beforeend', html);
    initAutocompleteForNewInputs();
}

// —— INVOICE ——
function createInvoiceRow(product = '', unit = 'шт', qty = '', price = '', sale_price = '') {
    const tr = document.createElement('tr');
    tr.innerHTML = `
<td><div class="autocomplete">
<input type="text" class="product-name autocomplete-input" value="${escapeHtml(product)}" placeholder="наименование" autocomplete="off">
<div class="autocomplete-items"></div>
</div></td>
<td><select class="unit"><option value="шт"${unit==='шт'?' selected':''}>шт</option><option value="кг"${unit==='кг'?' selected':''}>кг</option><option value="лотки"${unit==='лотки'?' selected':''}>лотки</option></select></td>
<td><input type="text" class="qty" value="${escapeHtml(qty)}" style="width:60px;text-align:right;"></td>
<td><input type="text" class="price" value="${escapeHtml(price)}" style="width:80px;text-align:right;"></td>
<td><input type="text" class="sale_price" value="${escapeHtml(sale_price)}" style="width:80px;text-align:right;"></td>
<td class="sum" style="text-align:right;">0 ₽</td>
<td><button class="btn-del" onclick="this.closest('tr').remove(); updateInvoiceTotal();">🗑</button></td>`;
    return tr;
}
function openInvoice() {
    document.getElementById('invoice-modal').style.display = 'flex';
    const tbody = document.getElementById('invoice-tbody');
    tbody.innerHTML = '';
    tbody.appendChild(createInvoiceRow());
    document.getElementById('invoice-date').value = new Date().toISOString().split('T')[0];
    tbody.querySelectorAll('.autocomplete-input').forEach(inp => delete inp.dataset.autocomplete);
    initAutocompleteForNewInputs();
    updateInvoiceTotal();
    tbody.querySelector('.product-name')?.focus();
}
function addInvoiceRow() {
    const tbody = document.getElementById('invoice-tbody');
    const row = createInvoiceRow();
    tbody.appendChild(row);
    tbody.querySelectorAll('.autocomplete-input').forEach(inp => delete inp.dataset.autocomplete);
    initAutocompleteForNewInputs();
    updateInvoiceTotal();
}
function updateInvoiceTotal() {
    let purchase = 0, sale = 0;
    document.querySelectorAll('#invoice-tbody tr').forEach(tr => {
        const q = parseFloat(tr.querySelector('.qty').value.replace(',', '.')) || 0;
        const p = parseFloat(tr.querySelector('.price').value.replace(',', '.')) || 0;
        const s = parseFloat(tr.querySelector('.sale_price')?.value.replace(',', '.') || 0);
        const sum = q * p;
        purchase += sum;
        sale += q * s;
        tr.querySelector('.sum').textContent = formatPrice(sum);
    });
    document.getElementById('invoice-total-purchase').textContent = formatPrice(purchase);
    document.getElementById('invoice-total-sale').textContent = formatPrice(sale);
    document.getElementById('invoice-total').textContent = formatPrice(purchase);
}
function saveInvoice() {
    const loc = document.getElementById('invoice-location').value;
    const date = document.getElementById('invoice-date').value;
    const rows = Array.from(document.querySelectorAll('#invoice-tbody tr')).map(tr => ({
        product_name: tr.querySelector('.product-name').value.trim(),
        unit: tr.querySelector('.unit').value,
        quantity: tr.querySelector('.qty').value.trim(),
        price: tr.querySelector('.price').value.trim(),
        sale_price: tr.querySelector('.sale_price')?.value.trim() || '',
        date: date
    })).filter(r => r.product_name && r.quantity && r.price);
    if (!rows.length) { alert('Добавьте хотя бы одну строку'); return; }
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=save_invoice&entries=` + encodeURIComponent(JSON.stringify(rows))
    }).then(parseJSONResponse).then(data => {
        if (data.success) {
            alert(`✅ Накладная сохранена (${data.added} строк)`);
            closeInvoice();
            loadData('today');
        } else alert('❌ Ошибка: ' + (data.error || 'неизвестно'));
    });
}
function closeInvoice() {
    document.getElementById('invoice-modal').style.display = 'none';
}

// —— EDIT INVOICE ——
function editInvoice(invoiceId) {
    document.getElementById('edit-invoice-modal').style.display = 'flex';
    document.getElementById('edit-invoice-id').textContent = invoiceId;
    fetch(`?action=load_invoice&invoice_id=${encodeURIComponent(invoiceId)}`)
    .then(parseJSONResponse).then(data => {
        if (!data.success) return alert('Ошибка загрузки');
        const tbody = document.getElementById('edit-invoice-tbody');
        tbody.innerHTML = '';
        document.getElementById('edit-invoice-date').value = data.date.split(' ')[0];
        document.getElementById('edit-invoice-location').value = data.location;
        data.rows.forEach(row => {
            const tr = createEditInvoiceRow(row.product_name, row.unit, row.quantity, row.price, row.sale_price, row.id);
            tbody.appendChild(tr);
        });
        tbody.querySelectorAll('.autocomplete-input').forEach(inp => delete inp.dataset.autocomplete);
        initAutocompleteForNewInputs();
        updateEditInvoiceTotal();
    });
}
function createEditInvoiceRow(product = '', unit = 'шт', qty = '', price = '', sale_price = '', id = '') {
    const tr = document.createElement('tr');
    tr.innerHTML = `
<td><div class="autocomplete"><input type="text" class="product-name autocomplete-input" value="${escapeHtml(product)}" placeholder="наименование" autocomplete="off" data-id="${escapeHtml(id)}"><div class="autocomplete-items"></div></div></td>
<td><select class="unit"><option value="шт"${unit==='шт'?' selected':''}>шт</option><option value="кг"${unit==='кг'?' selected':''}>кг</option><option value="лотки"${unit==='лотки'?' selected':''}>лотки</option></select></td>
<td><input type="text" class="qty" value="${escapeHtml(qty)}" style="width:60px;text-align:right;"></td>
<td><input type="text" class="price" value="${escapeHtml(price)}" style="width:80px;text-align:right;"></td>
<td><input type="text" class="sale_price" value="${escapeHtml(sale_price)}" style="width:80px;text-align:right;"></td>
<td class="sum" style="text-align:right;">0 ₽</td>
<td><button class="btn-del" onclick="this.closest('tr').remove(); updateEditInvoiceTotal();">🗑</button></td>`;
    return tr;
}
function addEditInvoiceRow() {
    const tbody = document.getElementById('edit-invoice-tbody');
    const row = createEditInvoiceRow();
    tbody.appendChild(row);
    tbody.querySelectorAll('.autocomplete-input').forEach(inp => delete inp.dataset.autocomplete);
    initAutocompleteForNewInputs();
    updateEditInvoiceTotal();
}
function updateEditInvoiceTotal() {
    let purchase = 0, sale = 0;
    document.querySelectorAll('#edit-invoice-tbody tr').forEach(tr => {
        const q = parseFloat(tr.querySelector('.qty').value.replace(',', '.')) || 0;
        const p = parseFloat(tr.querySelector('.price').value.replace(',', '.')) || 0;
        const s = parseFloat(tr.querySelector('.sale_price')?.value.replace(',', '.') || 0);
        const sum = q * p;
        purchase += sum;
        sale += q * s;
        tr.querySelector('.sum').textContent = formatPrice(sum);
    });
    document.getElementById('edit-invoice-total-purchase').textContent = formatPrice(purchase);
    document.getElementById('edit-invoice-total-sale').textContent = formatPrice(sale);
    document.getElementById('edit-invoice-total').textContent = formatPrice(purchase);
}
function saveEditInvoice() {
    const invoiceId = document.getElementById('edit-invoice-id').textContent;
    const location = document.getElementById('edit-invoice-location').value;
    const date = document.getElementById('edit-invoice-date').value;
    const entries = Array.from(document.querySelectorAll('#edit-invoice-tbody tr')).map(tr => ({
        id: tr.querySelector('.product-name').dataset.id || null,
        product_name: tr.querySelector('.product-name').value.trim(),
        unit: tr.querySelector('.unit').value,
        quantity: tr.querySelector('.qty').value.trim(),
        price: tr.querySelector('.price').value.trim(),
        sale_price: tr.querySelector('.sale_price')?.value.trim() || '',
        location: location,
        date: date
    })).filter(e => e.product_name && e.quantity && e.price);
    if (!entries.length) { alert('Добавьте хотя бы одну корректную строку'); return; }

    // Показываем прогресс-бар и блокируем кнопку
    const progressContainer = document.getElementById('edit-invoice-progress');
    const progressFill = document.getElementById('edit-invoice-progress-fill');
    const progressText = document.getElementById('edit-invoice-progress-text');
    const saveBtn = document.getElementById('edit-invoice-save-btn');
    
    progressContainer.classList.add('active');
    saveBtn.disabled = true;
    saveBtn.textContent = '⏳ Сохранение...';
    
    async function saveEntriesSequentially() {
        let successCount = 0;
        let failedCount = 0;
        const errors = [];
        const totalSteps = entries.reduce((sum, e) => sum + (e.id ? 7 : 1), 0);
        let currentStep = 0;
        
        function updateProgress(step, text) {
            currentStep += step;
            const percent = Math.round((currentStep / totalSteps) * 100);
            progressFill.style.width = percent + '%';
            progressFill.textContent = percent + '%';
            progressText.textContent = text;
        }
        
        console.log(`Начинаем сохранение ${entries.length} записей...`);
        updateProgress(0, `Начало сохранения ${entries.length} записей...`);
        
        for (let i = 0; i < entries.length; i++) {
            const entry = entries[i];
            const entryNum = i + 1;
            console.log(`Обработка записи ${entryNum}/${entries.length}, ID: ${entry.id || 'новая'}`);
            updateProgress(0, `Запись ${entryNum}/${entries.length}: ${entry.product_name || 'новая'}`);
            
            if (entry.id) {
                // Обновляем поля последовательно с задержкой
                const fields = [
                    { field: 'product_name', value: entry.product_name },
                    { field: 'unit', value: entry.unit },
                    { field: 'quantity', value: entry.quantity },
                    { field: 'price', value: entry.price },
                    { field: 'sale_price', value: entry.sale_price },
                    { field: 'location', value: entry.location },
                    { field: 'date', value: entry.date }
                ];
                
                let entrySuccess = true;
                let entryErrors = [];
                
                for (let j = 0; j < fields.length; j++) {
                    try {
                        updateProgress(0, `Запись ${entryNum}/${entries.length}: обновление ${fields[j].field}...`);
                        const result = await fetchPost('update_cell', { 
                            id: entry.id, 
                            field: fields[j].field, 
                            value: fields[j].value 
                        });
                        
                        if (!result) {
                            entrySuccess = false;
                            entryErrors.push(`${fields[j].field}: нет ответа`);
                            console.error(`Запись ${entry.id}, поле ${fields[j].field}: нет ответа`);
                        } else if (!result.success) {
                            entrySuccess = false;
                            const errMsg = result.error || 'неизвестная ошибка';
                            entryErrors.push(`${fields[j].field}: ${errMsg}`);
                            console.error(`Запись ${entry.id}, поле ${fields[j].field}:`, result);
                        } else {
                            console.log(`✓ Запись ${entry.id}, поле ${fields[j].field}: успешно`);
                        }
                        updateProgress(1, `Запись ${entryNum}/${entries.length}: ${fields[j].field} обновлено`);
                    } catch (err) {
                        entrySuccess = false;
                        entryErrors.push(`${fields[j].field}: ${err.message || err}`);
                        console.error(`Запись ${entry.id}, поле ${fields[j].field}, исключение:`, err);
                        updateProgress(1, `Запись ${entryNum}/${entries.length}: ошибка ${fields[j].field}`);
                    }
                    
                    // Уменьшил задержку между запросами до 50мс
                    if (j < fields.length - 1) {
                        await new Promise(resolve => setTimeout(resolve, 50));
                    }
                }
                
                if (entrySuccess) {
                    successCount++;
                    console.log(`✓ Запись ${entry.id} обновлена успешно`);
                } else {
                    failedCount++;
                    errors.push(`Запись ${entry.id}: ${entryErrors.join(', ')}`);
                    console.error(`✗ Запись ${entry.id} не обновлена:`, entryErrors);
                }
            } else {
                // Новая запись
                try {
                    updateProgress(0, `Запись ${entryNum}/${entries.length}: добавление новой...`);
                    const result = await fetchPost('save_invoice_row', {
                        invoice_id: invoiceId,
                        entry: JSON.stringify(entry)
                    });
                    if (result && result.success) {
                        successCount++;
                        console.log(`✓ Новая запись добавлена успешно`);
                        updateProgress(1, `Запись ${entryNum}/${entries.length}: добавлена`);
                    } else {
                        failedCount++;
                        const errMsg = result?.error || 'неизвестная ошибка';
                        errors.push(`Новая запись: ${errMsg}`);
                        console.error(`✗ Новая запись не добавлена:`, result);
                        updateProgress(1, `Запись ${entryNum}/${entries.length}: ошибка`);
                    }
                } catch (err) {
                    failedCount++;
                    errors.push(`Новая запись: ${err.message || err}`);
                    console.error(`✗ Новая запись, исключение:`, err);
                    updateProgress(1, `Запись ${entryNum}/${entries.length}: ошибка`);
                }
            }
            
            // Уменьшил задержку между записями до 100мс
            if (i < entries.length - 1) {
                await new Promise(resolve => setTimeout(resolve, 100));
            }
        }
        
        updateProgress(0, 'Завершение...');
        console.log(`Итого: успешно ${successCount}, ошибок ${failedCount}`);
        if (errors.length > 0) {
            console.error('Детали ошибок:', errors);
        }
        
        // Скрываем прогресс-бар и разблокируем кнопку
        setTimeout(() => {
            progressContainer.classList.remove('active');
            saveBtn.disabled = false;
            saveBtn.textContent = '✅ Сохранить';
        }, 500);
        
        if (failedCount > 0) {
            const errorDetails = errors.slice(0, 5).join('\n');
            const moreErrors = errors.length > 5 ? `\n... и ещё ${errors.length - 5} ошибок` : '';
            alert(`⚠️ Обновлено: ${successCount} из ${entries.length} записей. Ошибок: ${failedCount}\n\nДетали:\n${errorDetails}${moreErrors}\n\nОткройте консоль (F12) для полного лога.`);
        } else {
            alert('✅ Изменения сохранены');
        }
        closeEditInvoice();
        loadData('today');
    }
    
    saveEntriesSequentially().catch(err => {
        console.error('Критическая ошибка:', err);
        progressContainer.classList.remove('active');
        saveBtn.disabled = false;
        saveBtn.textContent = '✅ Сохранить';
        alert('❌ Ошибка при сохранении: ' + (err.message || err));
    });
}
function closeEditInvoice() {
    const progressContainer = document.getElementById('edit-invoice-progress');
    const saveBtn = document.getElementById('edit-invoice-save-btn');
    if (progressContainer) progressContainer.classList.remove('active');
    if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.textContent = '✅ Сохранить';
    }
    document.getElementById('edit-invoice-modal').style.display = 'none';
}

// —— EXPENSE ——
function createExpenseRow(product = '', unit = 'шт', qty = '', sale_price = '') {
    const tr = document.createElement('tr');
    tr.innerHTML = `
<td><div class="autocomplete"><input type="text" class="product-name autocomplete-input" value="${escapeHtml(product)}" placeholder="наименование" autocomplete="off"><div class="autocomplete-items"></div></div></td>
<td><select class="unit"><option value="шт"${unit==='шт'?' selected':''}>шт</option><option value="кг"${unit==='кг'?' selected':''}>кг</option><option value="лотки"${unit==='лотки'?' selected':''}>лотки</option></select></td>
<td><input type="text" class="qty" value="${escapeHtml(qty)}" style="width:60px;text-align:right;"></td>
<td><input type="text" class="sale_price" value="${escapeHtml(sale_price)}" style="width:80px;text-align:right;"></td>
<td class="sum" style="text-align:right;">0 ₽</td>
<td><button class="btn-del" onclick="this.closest('tr').remove(); updateExpenseTotal();">🗑</button></td>`;
    return tr;
}
function openExpenseInvoice() {
    document.getElementById('expense-modal').style.display = 'flex';
    const tbody = document.getElementById('expense-tbody');
    tbody.innerHTML = '';
    tbody.appendChild(createExpenseRow());
    document.getElementById('expense-date').value = new Date().toISOString().split('T')[0];
    tbody.querySelectorAll('.autocomplete-input').forEach(inp => delete inp.dataset.autocomplete);
    initAutocompleteForNewInputs();
    updateExpenseTotal();
    tbody.querySelector('.product-name')?.focus();
}
function addExpenseRow() {
    const tbody = document.getElementById('expense-tbody');
    const row = createExpenseRow();
    tbody.appendChild(row);
    tbody.querySelectorAll('.autocomplete-input').forEach(inp => delete inp.dataset.autocomplete);
    initAutocompleteForNewInputs();
    updateExpenseTotal();
}
function updateExpenseTotal() {
    let total = 0;
    document.querySelectorAll('#expense-tbody tr').forEach(tr => {
        const q = parseFloat(tr.querySelector('.qty').value.replace(',', '.')) || 0;
        const s = parseFloat(tr.querySelector('.sale_price').value.replace(',', '.')) || 0;
        const sum = q * s;
        total += sum;
        tr.querySelector('.sum').textContent = formatPrice(sum);
    });
    document.getElementById('expense-total').textContent = formatPrice(total);
}
function saveExpenseInvoice() {
    const loc = document.getElementById('expense-location').value;
    const date = document.getElementById('expense-date').value;
    const rows = Array.from(document.querySelectorAll('#expense-tbody tr')).map(tr => ({
        product_name: tr.querySelector('.product-name').value.trim(),
        unit: tr.querySelector('.unit').value,
        quantity: tr.querySelector('.qty').value.trim(),
        sale_price: tr.querySelector('.sale_price').value.trim(),
        date: date
    })).filter(r => r.product_name && r.quantity && r.sale_price);
    if (!rows.length) { alert('Добавьте хотя бы одну строку'); return; }
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=save_expense_invoice&entries=` + encodeURIComponent(JSON.stringify(rows))
    }).then(parseJSONResponse).then(data => {
        if (data.success) {
            alert(`✅ Расход сохранён (${data.added} строк)`);
            closeExpenseInvoice();
            loadData('today');
        } else alert('❌ Ошибка: ' + (data.error || 'неизвестно'));
    });
}
function closeExpenseInvoice() {
    document.getElementById('expense-modal').style.display = 'none';
}

// —— EDIT EXPENSE ——
function editExpense(expenseId) {
    document.getElementById('edit-expense-modal').style.display = 'flex';
    document.getElementById('edit-expense-id').textContent = expenseId;
    fetch(`?action=load_invoice&invoice_id=${encodeURIComponent(expenseId)}`)
    .then(parseJSONResponse).then(data => {
        if (!data.success) return alert('Ошибка загрузки');
        const tbody = document.getElementById('edit-expense-tbody');
        tbody.innerHTML = '';
        document.getElementById('edit-expense-date').value = data.date.split(' ')[0];
        document.getElementById('edit-expense-location').value = data.location;
        data.rows.forEach(row => {
            const tr = createEditExpenseRow(row.product_name, row.unit, row.quantity, row.sale_price, row.id);
            tbody.appendChild(tr);
        });
        tbody.querySelectorAll('.autocomplete-input').forEach(inp => delete inp.dataset.autocomplete);
        initAutocompleteForNewInputs();
        updateEditExpenseTotal();
    });
}
function createEditExpenseRow(product = '', unit = 'шт', qty = '', sale_price = '', id = '') {
    const tr = document.createElement('tr');
    tr.innerHTML = `
<td><div class="autocomplete"><input type="text" class="product-name autocomplete-input" value="${escapeHtml(product)}" placeholder="наименование" autocomplete="off" data-id="${escapeHtml(id)}"><div class="autocomplete-items"></div></div></td>
<td><select class="unit"><option value="шт"${unit==='шт'?' selected':''}>шт</option><option value="кг"${unit==='кг'?' selected':''}>кг</option><option value="лотки"${unit==='лотки'?' selected':''}>лотки</option></select></td>
<td><input type="text" class="qty" value="${escapeHtml(qty)}" style="width:60px;text-align:right;"></td>
<td><input type="text" class="sale_price" value="${escapeHtml(sale_price)}" style="width:80px;text-align:right;"></td>
<td class="sum" style="text-align:right;">0 ₽</td>
<td><button class="btn-del" onclick="this.closest('tr').remove(); updateEditExpenseTotal();">🗑</button></td>`;
    return tr;
}
function addEditExpenseRow() {
    const tbody = document.getElementById('edit-expense-tbody');
    const row = createEditExpenseRow();
    tbody.appendChild(row);
    tbody.querySelectorAll('.autocomplete-input').forEach(inp => delete inp.dataset.autocomplete);
    initAutocompleteForNewInputs();
    updateEditExpenseTotal();
}
function updateEditExpenseTotal() {
    let total = 0;
    document.querySelectorAll('#edit-expense-tbody tr').forEach(tr => {
        const q = parseFloat(tr.querySelector('.qty').value.replace(',', '.')) || 0;
        const s = parseFloat(tr.querySelector('.sale_price').value.replace(',', '.')) || 0;
        const sum = q * s;
        total += sum;
        tr.querySelector('.sum').textContent = formatPrice(sum);
    });
    document.getElementById('edit-expense-total').textContent = formatPrice(total);
}
function saveEditExpense() {
    const expenseId = document.getElementById('edit-expense-id').textContent;
    const location = document.getElementById('edit-expense-location').value;
    const date = document.getElementById('edit-expense-date').value;
    const entries = Array.from(document.querySelectorAll('#edit-expense-tbody tr')).map(tr => ({
        id: tr.querySelector('.product-name').dataset.id || null,
        product_name: tr.querySelector('.product-name').value.trim(),
        unit: tr.querySelector('.unit').value,
        quantity: tr.querySelector('.qty').value.trim(),
        sale_price: tr.querySelector('.sale_price').value.trim(),
        location: location,
        date: date
    })).filter(e => e.product_name && e.quantity && e.sale_price);
    if (!entries.length) { alert('Добавьте хотя бы одну корректную строку'); return; }

    // Показываем прогресс-бар и блокируем кнопку
    const progressContainer = document.getElementById('edit-expense-progress');
    const progressFill = document.getElementById('edit-expense-progress-fill');
    const progressText = document.getElementById('edit-expense-progress-text');
    const saveBtn = document.getElementById('edit-expense-save-btn');
    
    progressContainer.classList.add('active');
    saveBtn.disabled = true;
    saveBtn.textContent = '⏳ Сохранение...';
    
    async function saveEntriesSequentially() {
        let successCount = 0;
        let failedCount = 0;
        const errors = [];
        const totalSteps = entries.reduce((sum, e) => sum + (e.id ? 6 : 1), 0);
        let currentStep = 0;
        
        function updateProgress(step, text) {
            currentStep += step;
            const percent = Math.round((currentStep / totalSteps) * 100);
            progressFill.style.width = percent + '%';
            progressFill.textContent = percent + '%';
            progressText.textContent = text;
        }
        
        console.log(`Начинаем сохранение ${entries.length} записей...`);
        updateProgress(0, `Начало сохранения ${entries.length} записей...`);
        
        for (let i = 0; i < entries.length; i++) {
            const entry = entries[i];
            const entryNum = i + 1;
            console.log(`Обработка записи ${entryNum}/${entries.length}, ID: ${entry.id || 'новая'}`);
            updateProgress(0, `Запись ${entryNum}/${entries.length}: ${entry.product_name || 'новая'}`);
            
            if (entry.id) {
                // Обновляем поля последовательно с задержкой
                const fields = [
                    { field: 'product_name', value: entry.product_name },
                    { field: 'unit', value: entry.unit },
                    { field: 'quantity', value: entry.quantity },
                    { field: 'sale_price', value: entry.sale_price },
                    { field: 'location', value: entry.location },
                    { field: 'date', value: entry.date }
                ];
                
                let entrySuccess = true;
                let entryErrors = [];
                
                for (let j = 0; j < fields.length; j++) {
                    try {
                        updateProgress(0, `Запись ${entryNum}/${entries.length}: обновление ${fields[j].field}...`);
                        const result = await fetchPost('update_cell', { 
                            id: entry.id, 
                            field: fields[j].field, 
                            value: fields[j].value 
                        });
                        
                        if (!result) {
                            entrySuccess = false;
                            entryErrors.push(`${fields[j].field}: нет ответа`);
                            console.error(`Запись ${entry.id}, поле ${fields[j].field}: нет ответа`);
                        } else if (!result.success) {
                            entrySuccess = false;
                            const errMsg = result.error || 'неизвестная ошибка';
                            entryErrors.push(`${fields[j].field}: ${errMsg}`);
                            console.error(`Запись ${entry.id}, поле ${fields[j].field}:`, result);
                        } else {
                            console.log(`✓ Запись ${entry.id}, поле ${fields[j].field}: успешно`);
                        }
                        updateProgress(1, `Запись ${entryNum}/${entries.length}: ${fields[j].field} обновлено`);
                    } catch (err) {
                        entrySuccess = false;
                        entryErrors.push(`${fields[j].field}: ${err.message || err}`);
                        console.error(`Запись ${entry.id}, поле ${fields[j].field}, исключение:`, err);
                        updateProgress(1, `Запись ${entryNum}/${entries.length}: ошибка ${fields[j].field}`);
                    }
                    
                    // Уменьшил задержку между запросами до 50мс
                    if (j < fields.length - 1) {
                        await new Promise(resolve => setTimeout(resolve, 50));
                    }
                }
                
                if (entrySuccess) {
                    successCount++;
                    console.log(`✓ Запись ${entry.id} обновлена успешно`);
                } else {
                    failedCount++;
                    errors.push(`Запись ${entry.id}: ${entryErrors.join(', ')}`);
                    console.error(`✗ Запись ${entry.id} не обновлена:`, entryErrors);
                }
            } else {
                // Новая запись
                try {
                    updateProgress(0, `Запись ${entryNum}/${entries.length}: добавление новой...`);
                    const result = await fetchPost('save_expense_row', {
                        invoice_id: expenseId,
                        entry: JSON.stringify(entry)
                    });
                    if (result && result.success) {
                        successCount++;
                        console.log(`✓ Новая запись добавлена успешно`);
                        updateProgress(1, `Запись ${entryNum}/${entries.length}: добавлена`);
                    } else {
                        failedCount++;
                        const errMsg = result?.error || 'неизвестная ошибка';
                        errors.push(`Новая запись: ${errMsg}`);
                        console.error(`✗ Новая запись не добавлена:`, result);
                        updateProgress(1, `Запись ${entryNum}/${entries.length}: ошибка`);
                    }
                } catch (err) {
                    failedCount++;
                    errors.push(`Новая запись: ${err.message || err}`);
                    console.error(`✗ Новая запись, исключение:`, err);
                    updateProgress(1, `Запись ${entryNum}/${entries.length}: ошибка`);
                }
            }
            
            // Уменьшил задержку между записями до 100мс
            if (i < entries.length - 1) {
                await new Promise(resolve => setTimeout(resolve, 100));
            }
        }
        
        updateProgress(0, 'Завершение...');
        console.log(`Итого: успешно ${successCount}, ошибок ${failedCount}`);
        if (errors.length > 0) {
            console.error('Детали ошибок:', errors);
        }
        
        // Скрываем прогресс-бар и разблокируем кнопку
        setTimeout(() => {
            progressContainer.classList.remove('active');
            saveBtn.disabled = false;
            saveBtn.textContent = '✅ Сохранить';
        }, 500);
        
        if (failedCount > 0) {
            const errorDetails = errors.slice(0, 5).join('\n');
            const moreErrors = errors.length > 5 ? `\n... и ещё ${errors.length - 5} ошибок` : '';
            alert(`⚠️ Обновлено: ${successCount} из ${entries.length} записей. Ошибок: ${failedCount}\n\nДетали:\n${errorDetails}${moreErrors}\n\nОткройте консоль (F12) для полного лога.`);
        } else {
            alert('✅ Изменения сохранены');
        }
        closeEditExpense();
        loadData('today');
    }
    
    saveEntriesSequentially().catch(err => {
        console.error('Критическая ошибка:', err);
        progressContainer.classList.remove('active');
        saveBtn.disabled = false;
        saveBtn.textContent = '✅ Сохранить';
        alert('❌ Ошибка при сохранении: ' + (err.message || err));
    });
}
function closeEditExpense() {
    const progressContainer = document.getElementById('edit-expense-progress');
    const saveBtn = document.getElementById('edit-expense-save-btn');
    if (progressContainer) progressContainer.classList.remove('active');
    if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.textContent = '✅ Сохранить';
    }
    document.getElementById('edit-expense-modal').style.display = 'none';
}

// —— HELPERS ——
function fetchPost(action, data = {}) {
    const body = Object.entries({ action, ...data }).map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&');
    return fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body
    }).then(r => {
        if (!r.ok) {
            return { success: false, error: `HTTP ${r.status}: ${r.statusText}` };
        }
        return r.json().catch(() => ({ success: false, error: 'Ошибка парсинга ответа' }));
    }).catch(err => {
        return { success: false, error: err.message || 'Ошибка сети' };
    });
}

// —— MAIN FORM ——
document.getElementById('main-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const statusEl = document.getElementById('status');
    statusEl.textContent = 'Сохранение...';
    const formData = new FormData(this);
    fetch('', { method: 'POST', body: formData })
    .then(parseJSONResponse)
    .then(data => {
        if (data.success) {
            statusEl.textContent = data.message;
            setTimeout(() => loadData('today'), 300);
            this.reset();
            document.getElementById('entries').innerHTML = document.querySelector('.entry').outerHTML;
            count = 1;
            initAutocompleteForNewInputs();
        } else {
            statusEl.textContent = 'Ошибка сохранения';
        }
    });
});

// —— LOAD DATA ——
function loadData(mode) {
    const statusEl = document.getElementById('status');
    statusEl.textContent = 'Загрузка...';
    let df = document.getElementById('date_from').value;
    let dt = document.getElementById('date_to').value;
    if (mode === 'custom' && (!df || !dt)) { alert('Укажите обе даты'); return; }
    fetchPost('load_data', { mode, date_from: df, date_to: dt })
    .then(data => {
        if (data.success) {
            const tbody = document.getElementById('table-body');
            tbody.innerHTML = data.rows.map(row => {
                const sp = row.sale_price ? formatPrice(row.sale_price) + ' ₽' : '';
                const btn = row.invoice_id ? `<button class="btn-filter" style="padding:3px 6px;" onclick="editInvoiceOrExpense('${row.invoice_id}')">📄</button>` : '';
                return `<tr data-id="${row.id}">
<td>${row.id}</td>
<td class="editable" data-field="date" data-edit="${row.date_edit}">${row.date}</td>
<td class="editable" data-field="product_name">${escapeHtml(row.product_name)}</td>
<td class="editable" data-field="unit">${escapeHtml(row.unit)}</td>
<td class="editable" data-field="quantity" style="text-align:right">${row.quantity}</td>
<td class="editable" data-field="price" style="text-align:right">${formatPrice(row.price)}</td>
<td class="editable" data-field="sale_price" style="text-align:right">${sp}</td>
<td class="editable" data-field="location">${escapeHtml(row.location)}</td>
<td class="editable type-${escapeHtml(row.operation_type)}" data-field="operation_type">${escapeHtml(row.operation_type)}</td>
<td>${btn}</td>
<td class="actions"><button class="btn-del" onclick="deleteRow(${row.id})">🗑</button></td>
</tr>`;
            }).join('');
            document.querySelectorAll('.btn-filter').forEach(b => b.classList.remove('active'));
            document.querySelector(`.btn-filter${mode==='today'?':first-child':mode==='week'?':nth-child(2)':mode==='month'?':nth-child(3)':''}`).classList.add('active');
            statusEl.textContent = `Загружено: ${data.rows.length} записей`;
        } else {
            statusEl.textContent = 'Ошибка загрузки';
        }
    });
}

// —— INLINE EDIT ——
document.addEventListener('dblclick', function(e) {
    const cell = e.target.closest('.editable');
    if (!cell || cell.tagName === 'SELECT') return;
    const row = cell.closest('tr');
    const id = row.dataset.id;
    const field = cell.dataset.field;
    let currentValue = cell.dataset.edit || cell.textContent.trim().replace(/\s₽$/, '');
    if (field === 'date') {
        currentValue = cell.dataset.edit || new Date().toISOString().split('T')[0];
    }
    const input = document.createElement('input');
    input.type = field === 'date' ? 'date' : 'text';
    input.value = currentValue;
    input.className = 'editing';
    input.style.cssText = 'width:100%;padding:4px;border:1px solid #ff9800;';
    cell.innerHTML = ''; cell.appendChild(input);
    input.focus();
    input.addEventListener('keydown', ev => {
        if (ev.key === 'Enter') saveEdit(id, field, input.value, cell);
        else if (ev.key === 'Escape') cell.textContent = currentValue;
    });
    input.addEventListener('blur', () => setTimeout(() => {
        if (input.parentElement) cell.textContent = currentValue;
    }, 100));
});
function saveEdit(id, field, value, cell) {
    fetchPost('update_cell', { id, field, value })
    .then(data => {
        if (data.success) {
            let display = value;
            if (field === 'date') {
                const d = new Date(value);
                display = d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
            } else if (['price','sale_price'].includes(field)) {
                const n = parseFloat(value.replace(',', '.'));
                display = isNaN(n) ? '' : n.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' ₽';
            }
            cell.textContent = display;
            cell.dataset.edit = value;
            if (field === 'operation_type') cell.className = 'editable type-' + escapeHtml(value);
        } else {
            alert('Ошибка сохранения');
            cell.textContent = value;
        }
    });
}
function deleteRow(id) {
    if (!confirm('Точно удалить запись №' + id + '?')) return;
    fetchPost('delete_row', { id }).then(data => {
        if (data.success) {
            document.querySelector(`tr[data-id="${id}"]`)?.remove();
            document.getElementById('status').textContent = `Загружено: ${document.querySelectorAll('#table-body tr').length} записей`;
        } else {
            alert('Ошибка удаления');
        }
    });
}

// —— SWITCHER ——
function editInvoiceOrExpense(invoiceId) {
    if (invoiceId.startsWith('EXP_')) {
        editExpense(invoiceId);
    } else {
        editInvoice(invoiceId);
    }
}
// Печать текущего диапазона — формируем URL и открываем в новой вкладке
function printCurrent() {
    const activeBtn = document.querySelector('.filters .btn-filter.active');
    let mode = 'custom';
    if (activeBtn) {
        const txt = activeBtn.textContent.trim();
        if (txt === 'Сегодня') mode = 'today';
        else if (txt === 'Неделя') mode = 'week';
        else if (txt === 'Месяц') mode = 'month';
    }
    const from = document.getElementById('date_from').value;
    const to = document.getElementById('date_to').value;
    const params = new URLSearchParams();
    params.set('print', '1');
    params.set('mode', mode);
    if (mode === 'custom') {
        if (!from || !to) { alert('Укажите обе даты для печати'); return; }
        params.set('date_from', from);
        params.set('date_to', to);
    }
    window.open(window.location.pathname + '?' + params.toString(), '_blank');
}
</script>
</body>
</html>