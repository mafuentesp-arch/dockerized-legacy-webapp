<?php
$sqlitePath = 'C:/xampp/htdocs/esol/asistencia_zoom.db';
$sqlitePath = 'C:/Users/UB/PycharmProjects/ZoomReport/asistencia_zoom.db';

$sqlite = new PDO("sqlite:" . $sqlitePath);
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

echo "<h3>applebaum_attendance columns</h3>";

$cols = $sqlite->query("PRAGMA table_info(applebaum_attendance)")->fetchAll();

foreach ($cols as $c) {
    echo $c['name'] . " - " . $c['type'] . "<br>";
}

echo "<h3>Sample records</h3>";

$rows = $sqlite->query("SELECT * FROM applebaum_attendance LIMIT 5")->fetchAll();

echo "<pre>";
print_r($rows);
echo "</pre>";
?>