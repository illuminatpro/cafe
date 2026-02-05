<?php
require_once 'connect.php';
$sql = "SELECT period, MIN(date) AS from_date, MAX(date) AS to_date, COUNT(*) AS ops 
        FROM operations 
        GROUP BY period 
        ORDER BY period DESC";
$result = mysqli_query($link, $sql);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>📁 Архив периодов</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f0f0f0; }
        .period { background: white; margin: 10px 0; padding: 15px; border-radius: 6px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        a { text-decoration: none; color: #1976d2; font-weight: bold; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h2>📁 Архив периодов</h2>
    <?php while ($row = mysqli_fetch_assoc($result)): ?>
    <div class="period">
        <a href="analytics.php?period=<?= $row['period'] ?>">
            <?= $row['period'] ?>
        </a>
        <br>
        <small>
            с <?= date('d.m.Y', strtotime($row['from_date'])) ?> 
            по <?= date('d.m.Y', strtotime($row['to_date'])) ?> 
            (операций: <?= $row['ops'] ?>)
        </small>
    </div>
    <?php endwhile; ?>
    <p><a href="analytics.php">← Текущий период</a></p>
</body>
</html>