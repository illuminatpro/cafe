<?php
require_once 'connect.php';

// ---------- 0. AJAX для деталей (редактирование / удаление) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_POST['action'] === 'update_cell') {
        $id = (int)$_POST['id'];
        $field = mysqli_real_escape_string($link, $_POST['field']);
        $value = mysqli_real_escape_string($link, trim($_POST['value']));
        $allowed = ['product_name', 'unit', 'quantity', 'price', 'sale_price', 'location', 'operation_type', 'date'];
        if (!in_array($field, $allowed)) {
            echo json_encode(['success' => false]);
            exit;
        }
        if ($field === 'quantity' || $field === 'price' || $field === 'sale_price') {
            $value = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $value));
            $value = $value === '' ? 'NULL' : "'$value'";
        } elseif ($field === 'date') {
            if ($value === '') {
                $value = 'NULL';
            } else {
                $dateObj = DateTime::createFromFormat('Y-m-d', $value);
                if ($dateObj) {
                    $value = "'" . $dateObj->format('Y-m-d') . "'";
                } else {
                    $value = 'NULL';
                }
            }
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
}

// ---------- 1. Чтение параметров ----------
$location_filter = $_GET['location'] ?? 'все';          // все / кафе / магазин
$weekday_start   = isset($_GET['weekday_start']) ? (int)$_GET['weekday_start'] : 2; // 1=пн ... 7=вс, по умолчанию вторник
if ($weekday_start < 1 || $weekday_start > 7) {
    $weekday_start = 2;
}

// Дата начала недели
$start_date_param = $_GET['start_date'] ?? '';

// Если дата не передана – считаем начало текущей недели по выбранному дню
if ($start_date_param === '') {
    $today = new DateTime();
    $today_w = (int)$today->format('N'); // 1..7
    $diff = ($today_w - $weekday_start + 7) % 7;
    if ($diff > 0) {
        $today->modify("-{$diff} day");
    }
    $start_date = $today->format('Y-m-d');
} else {
    // простая валидация формата
    $d = DateTime::createFromFormat('Y-m-d', $start_date_param);
    $start_date = $d ? $d->format('Y-m-d') : date('Y-m-d');
}

// Дата конца недели (7 дней, включительно)
$start_dt = new DateTime($start_date);
$end_dt = clone $start_dt;
$end_dt->modify('+6 day');
$end_date = $end_dt->format('Y-m-d');

// Для заголовка
$start_date_human = date('d.m.Y', strtotime($start_date));
$end_date_human   = date('d.m.Y', strtotime($end_date));
// печать
$is_print = isset($_GET['print']) && $_GET['print'] == '1';

// ---------- 2. Общий фрагмент WHERE по локации ----------
$where_location_prev = '';
$where_location_week = '';

if ($location_filter !== 'все') {
    $loc_esc = mysqli_real_escape_string($link, $location_filter);
    $where_location_prev = " AND location = '{$loc_esc}'";
    $where_location_week = " AND location = '{$loc_esc}'";
}

// ---------- 3. Остатки до начала выбранной недели ----------
$prev_to = (new DateTime($start_date))->modify('-1 day')->format('Y-m-d');

$sql_prev = "
    SELECT
        product_name,
        unit,
        SUM(CASE WHEN operation_type = 'приход' THEN quantity ELSE 0 END) -
        SUM(CASE WHEN operation_type IN ('расход','списание') THEN quantity ELSE 0 END) AS prev_balance
    FROM operations
    WHERE date <= '{$prev_to}'{$where_location_prev}
    GROUP BY product_name, unit
";

$result_prev = mysqli_query($link, $sql_prev);
$prev_data = [];
if ($result_prev) {
    while ($row = mysqli_fetch_assoc($result_prev)) {
        $key = $row['product_name'] . '||' . $row['unit'];
        $prev_data[$key] = (float)$row['prev_balance'];
    }
}

// ---------- 4. Приход / расход и деньги за неделю ----------
$sql_week = "
    SELECT
        product_name,
        unit,
        SUM(CASE WHEN operation_type = 'приход' THEN quantity ELSE 0 END) AS week_in,
        SUM(CASE WHEN operation_type = 'расход' THEN quantity ELSE 0 END) AS week_sold,
        SUM(CASE WHEN operation_type = 'списание' THEN quantity ELSE 0 END) AS week_writeoff,

        -- Закупка за неделю (по приходу)
        SUM(CASE WHEN operation_type = 'приход' THEN quantity * price ELSE 0 END) AS week_cost,

        -- Выручка за неделю (по расходу, по sale_price если есть)
        SUM(CASE WHEN operation_type = 'расход' THEN quantity * COALESCE(sale_price, price) ELSE 0 END) AS week_revenue,

        -- Средняя закупочная цена за неделю
        CASE WHEN SUM(CASE WHEN operation_type = 'приход' THEN quantity ELSE 0 END) > 0
             THEN SUM(CASE WHEN operation_type = 'приход' THEN quantity * price ELSE 0 END) /
                  SUM(CASE WHEN operation_type = 'приход' THEN quantity ELSE 0 END)
             ELSE 0 END AS week_avg_purchase_price,

        -- Средняя цена продажи за неделю
        CASE WHEN SUM(CASE WHEN operation_type = 'расход' THEN quantity ELSE 0 END) > 0
             THEN SUM(CASE WHEN operation_type = 'расход' THEN quantity * COALESCE(sale_price, price) ELSE 0 END) /
                  SUM(CASE WHEN operation_type = 'расход' THEN quantity ELSE 0 END)
             ELSE 0 END AS week_avg_sale_price,

        -- Прибыль за неделю (как в analytics.php: выручка - себестоимость проданного)
        SUM(CASE WHEN operation_type = 'расход' THEN quantity * COALESCE(sale_price, price) ELSE 0 END) -
        CASE
            WHEN SUM(CASE WHEN operation_type = 'приход' THEN quantity ELSE 0 END) > 0
            THEN (
                SUM(CASE WHEN operation_type = 'расход' THEN quantity ELSE 0 END)
            ) * (
                SUM(CASE WHEN operation_type = 'приход' THEN quantity * price ELSE 0 END) /
                SUM(CASE WHEN operation_type = 'приход' THEN quantity ELSE 0 END)
            )
            ELSE 0 END AS week_profit
    FROM operations
    WHERE date BETWEEN '{$start_date}' AND '{$end_date}'{$where_location_week}
    GROUP BY product_name, unit
";

$result_week = mysqli_query($link, $sql_week);
$week_data = [];
$all_keys = [];

if ($result_week) {
    while ($row = mysqli_fetch_assoc($result_week)) {
        $key = $row['product_name'] . '||' . $row['unit'];
        $week_data[$key] = [
            'product_name' => $row['product_name'],
            'unit'         => $row['unit'],
            'week_in'      => (float)$row['week_in'],
            'week_sold'    => (float)$row['week_sold'],
            'week_writeoff'=> (float)$row['week_writeoff'],
            'week_cost'    => (float)$row['week_cost'],
            'week_revenue' => (float)$row['week_revenue'],
            'week_profit'  => (float)$row['week_profit'],
        ];
        $all_keys[$key] = true;
    }
}

// добавим ключи из остатков
foreach ($prev_data as $key => $_) {
    $all_keys[$key] = true;
}

// ---------- 5. Сбор итоговой таблицы ----------
$rows = [];

foreach (array_keys($all_keys) as $key) {
    [$product_name, $unit] = explode('||', $key);

    $prev_balance = $prev_data[$key] ?? 0.0;

    $week_in  = $week_data[$key]['week_in']  ?? 0.0;
    $week_sold     = $week_data[$key]['week_sold']     ?? 0.0;
    $week_writeoff = $week_data[$key]['week_writeoff'] ?? 0.0;
    $week_cost     = $week_data[$key]['week_cost']     ?? 0.0;
    $week_revenue  = $week_data[$key]['week_revenue']  ?? 0.0;
    $week_profit   = $week_data[$key]['week_profit']   ?? 0.0;

    $week_out = $week_sold + $week_writeoff;

    // если нет ни остатков, ни движений за неделю – пропускаем
    if ($prev_balance == 0.0 && $week_in == 0.0 && $week_out == 0.0) {
        continue;
    }

    $next_balance = $prev_balance + $week_in - $week_out;

    $rows[] = [
        'product_name'  => $product_name,
        'unit'          => $unit,
        'prev_balance'  => $prev_balance,
        'week_in'       => $week_in,
        'week_out'      => $week_out,
        'week_cost'     => $week_cost,
        'week_revenue'  => $week_revenue,
        'week_profit'   => $week_profit,
        'next_balance'  => $next_balance,
    ];
}

// сортировка по наименованию
usort($rows, function ($a, $b) {
    return strcmp(mb_strtolower($a['product_name']), mb_strtolower($b['product_name']));
});

// ---------- 6. Детали по товару за выбранную неделю ----------
$detail_product = $_GET['product'] ?? null;
$detail_rows = [];

if ($detail_product) {
    $product_esc = mysqli_real_escape_string($link, $detail_product);
    $where_loc_detail = '';
    if ($location_filter !== 'все') {
        $loc_esc = mysqli_real_escape_string($link, $location_filter);
        $where_loc_detail = " AND location = '{$loc_esc}'";
    }
    $sql_detail = "
        SELECT id, product_name, unit, quantity, price, sale_price, location, operation_type,
               DATE_FORMAT(date, '%Y-%m-%d') AS date_raw,
               DATE_FORMAT(date, '%d.%m.%Y') AS date_fmt
        FROM operations
        WHERE product_name = '{$product_esc}'
          AND date BETWEEN '{$start_date}' AND '{$end_date}'{$where_loc_detail}
        ORDER BY date DESC, id DESC
    ";
    $result_detail = mysqli_query($link, $sql_detail);
    if ($result_detail) {
        while ($row = mysqli_fetch_assoc($result_detail)) {
            $detail_rows[] = $row;
        }
    }
}

// Справочник дней недели
$weekday_labels = [
    1 => 'Понедельник',
    2 => 'Вторник',
    3 => 'Среда',
    4 => 'Четверг',
    5 => 'Пятница',
    6 => 'Суббота',
    7 => 'Воскресенье',
];
$current_weekday_name = $weekday_labels[$weekday_start] ?? '';
// Серверная печать: при ?print=1 выдаём полную таблицу по неделям и запускаем печать
$is_print = $is_print ?? (isset($_GET['print']) && $_GET['print'] == '1');
if ($is_print) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Печать — Аналитика №2</title>";
    echo "<style>body{font-family:Arial,Helvetica,sans-serif;padding:12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:6px;text-align:left}th{background:#f0f0f0}</style>";
    echo "</head><body>";
    echo "<h2>Аналитика №2 — остатки по неделям</h2>";
    echo "<table><thead><tr><th>Товар</th><th>Ед.</th><th>Ост_prev</th><th>Приход</th><th>Расход</th><th>Закупка</th><th>Продажа</th><th>Прибыль</th><th>Ост_next</th></tr></thead><tbody>";
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($r['unit'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td style="text-align:right">' . number_format($r['prev_balance'],2,',',' ') . '</td>';
        echo '<td style="text-align:right">' . number_format($r['week_in'],2,',',' ') . '</td>';
        echo '<td style="text-align:right">' . number_format($r['week_out'],2,',',' ') . '</td>';
        echo '<td style="text-align:right">' . number_format($r['week_cost'],2,',',' ') . ' ₽</td>';
        echo '<td style="text-align:right">' . number_format($r['week_revenue'],2,',',' ') . ' ₽</td>';
        echo '<td style="text-align:right">' . number_format($r['week_profit'],2,',',' ') . ' ₽</td>';
        echo '<td style="text-align:right">' . number_format($r['next_balance'],2,',',' ') . '</td>';
        echo '</tr>';
    }
    echo "</tbody></table><script>window.print();</script></body></html>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>📊 Аналитика №2 — по неделям</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0; padding: 12px;
            background: #e0e0e0; color: #212121;
        }
        .container { max-width: 1800px; margin: 0 auto; }
        .panel {
            background: #ffffff; border-radius: 8px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.12);
            padding: 20px; border: 1px solid #bdbdbd;
            margin-bottom: 20px;
        }
        .header-row {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 15px;
        }
        .header-row h2 {
            margin: 0; color: #37474f;
            border-bottom: 2px solid #90a4ae; padding-bottom: 6px;
        }
        .filters {
            margin-bottom: 15px; display: flex; flex-wrap: wrap;
            gap: 8px; align-items: center;
        }
        .filters label { margin: 0 8px 0 0; color: #546e7a; }
        button {
            padding: 6px 12px; margin: 4px 4px 4px 0;
            border: none; border-radius: 5px;
            cursor: pointer; font-weight: 600; font-size: 13px;
        }
        .btn-main { background: #546e7a; color: #ffffff; }
        .btn-filter { background: #78909c; color: #ffffff; }
        select, input[type="date"] {
            padding: 6px 10px; border: 1px solid #90a4ae;
            border-radius: 4px; background: #f5f7f9;
        }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td {
            padding: 8px 10px; text-align: left;
            border: 1px solid #cfd8dc;
        }
        th {
            background: #e0e0e0; font-weight: 600; color: #37474f;
            text-align: center;
        }
        tr:nth-child(even) { background: #f5f7f9; }
        tr:hover { background: #e3f2fd !important; }
        .num { text-align: right; }
        .positive { color: #2e7d32; font-weight: bold; }
        .negative { color: #c62828; font-weight: bold; }
        .zero { color: #607d8b; }
        .status { font-size: 13px; color: #607d8b; margin-top: 5px; }
    </style>
</head>
<body>

<div class="container">
    <?php if ($detail_product): ?>
        <div class="panel">
            <div class="header-row">
                <h2>📦 Детали движения: <?php echo htmlspecialchars($detail_product, ENT_QUOTES, 'UTF-8'); ?></h2>
                    <div>
                        <?php
                        $back_params = [
                            'location'      => $location_filter,
                            'weekday_start' => $weekday_start,
                            'start_date'    => $start_date,
                        ];
                        $back_query = http_build_query($back_params);
                        ?>
                        <button class="btn-main" onclick="window.location.href='analytic2.php?<?php echo htmlspecialchars($back_query, ENT_QUOTES, 'UTF-8'); ?>'">← К сводке недели</button>
                        <button class="btn-filter" onclick="window.open(window.location.pathname + window.location.search + (window.location.search ? '&' : '?') + 'print=1','_blank')">🖨️ Печать</button>
                    </div>
                </div>

                <div class="status">
                Период: <?php echo htmlspecialchars($start_date_human, ENT_QUOTES, 'UTF-8'); ?>
                — <?php echo htmlspecialchars($end_date_human, ENT_QUOTES, 'UTF-8'); ?>,
                место: <?php echo htmlspecialchars($location_filter, ENT_QUOTES, 'UTF-8'); ?>
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
                        <th>🗑</th>
                    </tr>
                    </thead>
                    <tbody id="detail-body">
                    <?php if (empty($detail_rows)): ?>
                        <tr>
                            <td colspan="9" style="text-align:center; color:#607d8b;">Нет движений за выбранную неделю</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($detail_rows as $row): ?>
                            <tr data-id="<?php echo (int)$row['id']; ?>">
                                <td><?php echo (int)$row['id']; ?></td>
                                <td class="editable" data-field="date" data-edit="<?php echo htmlspecialchars($row['date_raw'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($row['date_fmt'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td class="editable" data-field="unit"><?php echo htmlspecialchars($row['unit'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="editable" data-field="quantity" style="text-align:right;">
                                    <?php echo htmlspecialchars($row['quantity'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td class="editable" data-field="price" style="text-align:right;">
                                    <?php echo $row['price'] !== null ? number_format($row['price'], 2, ',', ' ') . ' ₽' : ''; ?>
                                </td>
                                <td class="editable" data-field="sale_price" style="text-align:right;">
                                    <?php echo $row['sale_price'] ? number_format($row['sale_price'], 2, ',', ' ') . ' ₽' : ''; ?>
                                </td>
                                <td class="editable" data-field="location"><?php echo htmlspecialchars($row['location'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="editable type-<?php echo htmlspecialchars($row['operation_type'], ENT_QUOTES, 'UTF-8'); ?>" data-field="operation_type">
                                    <?php echo htmlspecialchars($row['operation_type'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="text-align:center;">
                                    <button class="btn-main" style="background:#c62828;" onclick="deleteRow(<?php echo (int)$row['id']; ?>)">🗑</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="panel">
            <div class="header-row">
                <h2>📊 Аналитика №2 — остатки по неделям</h2>
                <div>
                    <button class="btn-main" onclick="window.location.href='analytics.php'">Основная аналитика</button>
                    <button class="btn-main" onclick="window.location.href='cafe.php'">Учёт операций</button>
                    <button class="btn-filter" onclick="window.open(window.location.pathname + window.location.search + (window.location.search ? '&' : '?') + 'print=1','_blank')">🖨️ Печать</button>
                </div>
            </div>

            <div class="filters">
                <label>Место:</label>
                <select id="location-select">
                    <option value="все"    <?php if ($location_filter === 'все')    echo 'selected'; ?>>все</option>
                    <option value="кафе"   <?php if ($location_filter === 'кафе')   echo 'selected'; ?>>кафе</option>
                    <option value="магазин"<?php if ($location_filter === 'магазин')echo 'selected'; ?>>магазин</option>
                </select>

                <span style="margin: 0 12px;">|</span>

                <label>День начала недели:</label>
                <select id="weekday-select">
                    <?php foreach ($weekday_labels as $num => $label): ?>
                        <option value="<?php echo $num; ?>" <?php if ($num === $weekday_start) echo 'selected'; ?>>
                            <?php echo $label; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <span style="margin: 0 12px;">|</span>

                <label>Дата начала недели:</label>
                <input type="date" id="start-date" value="<?php echo htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8'); ?>">

                <button class="btn-filter" onclick="applyWeek()">Показать</button>
                <button class="btn-filter" onclick="setPrevWeek()">Предыдущая неделя</button>
                <button class="btn-filter" onclick="setNextWeek()">Следующая неделя</button>
                <button class="btn-filter" onclick="setCurrentWeek()">Текущая неделя</button>

                <div class="status">
                    Неделя: <?php echo htmlspecialchars($start_date_human, ENT_QUOTES, 'UTF-8'); ?>
                    — <?php echo htmlspecialchars($end_date_human, ENT_QUOTES, 'UTF-8'); ?>
                    (начало: <?php echo htmlspecialchars($current_weekday_name, ENT_QUOTES, 'UTF-8'); ?>,
                    место: <?php echo htmlspecialchars($location_filter, ENT_QUOTES, 'UTF-8'); ?>)
                </div>
            </div>

            <div style="<?= $is_print ? 'max-height:none; overflow:visible;' : 'max-height:65vh; overflow-y:auto;' ?>">
                <table>
                    <thead>
                    <tr>
                        <th>Товар</th>
                        <th>Ед.</th>
                        <th>Остаток с предыдущей недели</th>
                        <th>Приход за неделю</th>
                        <th>Расход / списание за неделю</th>
                        <th>Закупка за неделю</th>
                        <th>Продажа за неделю</th>
                        <th>Прибыль за неделю</th>
                        <th>Остаток на следующую неделю</th>
                        <th>Детали</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="10" style="text-align:center; color:#607d8b;">Нет данных за выбранный интервал</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $prev  = $r['prev_balance'];
                            $in    = $r['week_in'];
                            $out   = $r['week_out'];
                            $cost  = $r['week_cost'];
                            $rev   = $r['week_revenue'];
                            $prof  = $r['week_profit'];
                            $next  = $r['next_balance'];

                            $prev_class = $prev  > 0 ? 'positive' : ($prev  < 0 ? 'negative' : 'zero');
                            $next_class = $next  > 0 ? 'positive' : ($next  < 0 ? 'negative' : 'zero');

                            $detail_params = [
                                'location'      => $location_filter,
                                'weekday_start' => $weekday_start,
                                'start_date'    => $start_date,
                                'product'       => $r['product_name'],
                            ];
                            $detail_query = http_build_query($detail_params);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($r['unit'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="num <?php echo $prev_class; ?>">
                                    <?php echo number_format($prev, 2, ',', ' '); ?>
                                </td>
                                <td class="num">
                                    <?php echo number_format($in, 2, ',', ' '); ?>
                                </td>
                                <td class="num">
                                    <?php echo number_format($out, 2, ',', ' '); ?>
                                </td>
                                <td class="num">
                                    <?php echo number_format($cost, 2, ',', ' '); ?> ₽
                                </td>
                                <td class="num">
                                    <?php echo number_format($rev, 2, ',', ' '); ?> ₽
                                </td>
                                <td class="num <?php echo $prof >= 0 ? 'positive' : 'negative'; ?>">
                                    <?php echo number_format($prof, 2, ',', ' '); ?> ₽
                                </td>
                                <td class="num <?php echo $next_class; ?>">
                                    <?php echo number_format($next, 2, ',', ' '); ?>
                                </td>
                                <td style="text-align:center;">
                                    <button class="btn-main" onclick="window.location.href='analytic2.php?<?php echo htmlspecialchars($detail_query, ENT_QUOTES, 'UTF-8'); ?>'">🔍</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($rows)): ?>
                <?php
                $total_cost    = array_sum(array_column($rows, 'week_cost'));
                $total_revenue = array_sum(array_column($rows, 'week_revenue'));
                $total_profit  = array_sum(array_column($rows, 'week_profit'));
                $profit_class  = $total_profit > 0 ? 'positive' : ($total_profit < 0 ? 'negative' : 'zero');
                ?>
                <div style="margin-top: 20px; padding: 15px; background: #f5f5f5; border-radius: 6px;">
                    <h3>Итого за неделю: <strong><?php echo htmlspecialchars($start_date_human, ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars($end_date_human, ENT_QUOTES, 'UTF-8'); ?></strong> (<?php echo htmlspecialchars($location_filter, ENT_QUOTES, 'UTF-8'); ?>)</h3>
                    <p><strong>Закупка (приход):</strong> <?php echo number_format($total_cost, 2, ',', ' '); ?> ₽</p>
                    <p><strong>Продажа (расход):</strong> <?php echo number_format($total_revenue, 2, ',', ' '); ?> ₽</p>
                    <p><strong>Прибыль:</strong> <span class="<?php echo $profit_class; ?>"><?php echo number_format($total_profit, 2, ',', ' '); ?> ₽</span></p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function buildUrl(params) {
    var url = new URL(window.location.href);
    url.search = '';
    for (var key in params) {
        if (Object.prototype.hasOwnProperty.call(params, key) && params[key] !== '') {
            url.searchParams.set(key, params[key]);
        }
    }
    return url.toString();
}

function applyWeek() {
    var loc     = document.getElementById('location-select').value;
    var weekday = document.getElementById('weekday-select').value;
    var start   = document.getElementById('start-date').value;

    if (!start) {
        alert('Укажите дату начала недели');
        return;
    }

    var href = buildUrl({
        location:      loc,
        weekday_start: weekday,
        start_date:    start
    });
    window.location.href = href;
}

function setCurrentWeek() {
    var loc     = document.getElementById('location-select').value;
    var weekday = document.getElementById('weekday-select').value;

    var href = buildUrl({
        location:      loc,
        weekday_start: weekday
    });
    window.location.href = href;
}

function setPrevWeek() {
    var loc     = document.getElementById('location-select').value;
    var weekday = document.getElementById('weekday-select').value;
    var start   = document.getElementById('start-date').value;
    if (!start) {
        alert('Укажите дату начала недели');
        return;
    }
    var d = new Date(start);
    d.setDate(d.getDate() - 7);
    var newStart = d.toISOString().slice(0, 10);
    var href = buildUrl({
        location:      loc,
        weekday_start: weekday,
        start_date:    newStart
    });
    window.location.href = href;
}

function setNextWeek() {
    var loc     = document.getElementById('location-select').value;
    var weekday = document.getElementById('weekday-select').value;
    var start   = document.getElementById('start-date').value;
    if (!start) {
        alert('Укажите дату начала недели');
        return;
    }
    var d = new Date(start);
    d.setDate(d.getDate() + 7);
    var newStart = d.toISOString().slice(0, 10);
    var href = buildUrl({
        location:      loc,
        weekday_start: weekday,
        start_date:    newStart
    });
    window.location.href = href;
}

// --- Редактирование в деталях ---
document.addEventListener('dblclick', function (e) {
    var cell = e.target.closest('.editable');
    if (!cell) return;
    var row = cell.closest('tr');
    var id = row.getAttribute('data-id');
    var field = cell.getAttribute('data-field');
    var currentValue = cell.getAttribute('data-edit') || cell.textContent.trim().replace(/\s₽$/, '');
    if (field === 'date' && !cell.getAttribute('data-edit')) {
        // дата уже в формате дд.мм.гггг – оставим как есть, пользователь введёт в формате Y-m-d
        currentValue = '';
    }

    var input = document.createElement('input');
    input.type = field === 'date' ? 'date' : 'text';
    input.value = currentValue;
    input.className = 'editing';
    input.style.width = '100%';
    input.style.padding = '4px';
    input.style.border = '1px solid #ff9800';
    cell.innerHTML = '';
    cell.appendChild(input);
    input.focus();

    input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
            saveEdit(id, field, input.value, cell);
        } else if (ev.key === 'Escape') {
            cell.textContent = currentValue;
        }
    });
    input.addEventListener('blur', function () {
        setTimeout(function () {
            if (input.parentElement) {
                cell.textContent = currentValue;
            }
        }, 100);
    });
});

function saveEdit(id, field, value, cell) {
    var formData = new URLSearchParams();
    formData.set('action', 'update_cell');
    formData.set('id', id);
    formData.set('field', field);
    formData.set('value', value);

    fetch('analytic2.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    }).then(function (r) { return r.json(); }).then(function (data) {
        if (data.success) {
            var displayValue = value;
            if (field === 'price' || field === 'sale_price') {
                var num = parseFloat(value.replace(',', '.'));
                displayValue = isNaN(num) ? '' : num.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' ₽';
            } else if (field === 'date') {
                if (value) {
                    var d = new Date(value);
                    displayValue = d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
                    cell.setAttribute('data-edit', value);
                } else {
                    displayValue = '';
                }
            }
            cell.textContent = displayValue;
            if (field === 'operation_type') {
                cell.className = 'editable type-' + escapeHtml(value);
            }
        } else {
            alert('Ошибка сохранения');
        }
    });
}

function deleteRow(id) {
    if (!confirm('Точно удалить запись №' + id + '?')) return;
    var formData = new URLSearchParams();
    formData.set('action', 'delete_row');
    formData.set('id', id);

    fetch('analytic2.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    }).then(function (r) { return r.json(); }).then(function (data) {
        if (data.success) {
            var tr = document.querySelector('tr[data-id="' + id + '"]');
            if (tr && tr.parentNode) {
                tr.parentNode.removeChild(tr);
            }
        } else {
            alert('Ошибка удаления');
        }
    });
}

function escapeHtml(str) {
    return str.replace(/[&<>"']/g, function (m) {
        return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m]);
    });
}
</script>

</body>
</html>


