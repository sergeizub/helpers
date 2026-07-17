<?php
// filepath: convert_utf8_to_utf8mb4.php

include_once("db.php");

$skipDbs = ['mysql', 'information_schema', 'performance_schema', 'sys'];

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, '', 3306);
if ($mysqli->connect_errno) {
    die("Connect failed: {$mysqli->connect_error}\n");
}
$mysqli->set_charset('utf8mb4');
$databases_for_select = $databases_for_process = array();
$database_name = isset($_POST['database_name']) ? $_POST['database_name'] : '';
$database_name_esc = $mysqli->real_escape_string($database_name);
$dryRun = isset($_POST['dry_run']) && $_POST['dry_run'] ? true : false;
$collation = isset($_POST['collation']) ? $_POST['collation'] : 'utf8mb4_general_ci';
$allowedCollations = ['utf8mb4_general_ci', 'utf8mb4_unicode_ci', 'utf8mb4_0900_ai_ci'];
if (!in_array($collation, $allowedCollations, true)) {
    $collation = 'utf8mb4_general_ci';
}
$logFile = __DIR__ . '/convert_character_set.log';

function logAction($message)
{
    global $logFile;
    $time = date('Y-m-d H:i:s');
    @file_put_contents($logFile, sprintf("%s | %s\n", $time, $message), FILE_APPEND | LOCK_EX);
}

if (!$dryRun) {
    logAction(sprintf('Started conversion: dryRun=%s, database=%s, collation=%s', $dryRun ? 'yes' : 'no', $database_name ?: 'ALL', $collation));
}

$dbListSql = "
    SELECT TABLE_SCHEMA, TABLE_NAME
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA NOT IN ('" . implode("','", $skipDbs) . "')
        AND TABLE_COLLATION NOT LIKE 'utf8mb4%'
    ORDER BY TABLE_SCHEMA ASC, TABLE_NAME ASC
";

$result = $mysqli->query($dbListSql);
if (!$result) {
    die("Error fetching databases: {$mysqli->error}\n");
}

while ($row = $result->fetch_assoc()) {
    $db = $mysqli->real_escape_string($row['TABLE_SCHEMA']);
    $table_name = $mysqli->real_escape_string($row['TABLE_NAME']);
    $databases_for_select[$db] = $db;
    if (empty($database_name) || $db == $database_name)
        $databases_for_process[$db][$table_name] = $table_name;
}

?>
    <form method="post">
        Select Database: 
        <select name="database_name" <?=(!empty($database_name)) ? 'disabled="disabled"' : "" ?>>
            <option value="">ALL Databases</option>
            <?php 
                foreach($databases_for_select as $row) {
                    $schema_row_name = $row;
                    $selected = "";
                    if (!empty($database_name) && $database_name == $schema_row_name)
                        $selected = 'selected = "selected"';
                    $value = htmlspecialchars($schema_row_name, ENT_QUOTES, 'UTF-8');
                    echo '<option value="'.$value.'" '.$selected.'>'.$value.'</option>';
                }
            ?>
        </select>
        <br/>
        <label for="collation">Collation:</label>
        <select name="collation" id="collation">
            <?php
                $collations = [
                    'utf8mb4_general_ci' => 'utf8mb4_general_ci',
                    'utf8mb4_unicode_ci' => 'utf8mb4_unicode_ci',
                    'utf8mb4_0900_ai_ci' => 'utf8mb4_0900_ai_ci',
                ];
                foreach ($collations as $value => $label) {
                    $selected = $collation === $value ? 'selected="selected"' : '';
                    echo '<option value="'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'" '.$selected.'>'.$label.'</option>';
                }
            ?>
        </select>
        </br>
        <?php if (!empty($database_name)) {
            // Preserve selected value when select is disabled
            echo '<input type="hidden" name="database_name" value="'.htmlspecialchars($database_name, ENT_QUOTES, 'UTF-8').'">';
        } ?>
        <input type="checkbox" name="dry_run" value="1" <?= $dryRun ? 'checked="checked"' : '' ?> /> Dry run (show SQL only)
        <br/>
        <input type="submit" name="submit" value="Submit" <?=(!empty($database_name)) ? 'disabled="disabled"' : "" ?>>
        <button type="button" onclick="window.location.href = 'convert_character_set.php'">Reset</button>
        </form>
<?php 

if ($_POST['submit'] ?? false) {
    foreach ($databases_for_process as $db => $tables) {
        printf("<br/><br/>Processing database: %s", $db);
        echo '<br/>';
        $alterDbSql = "ALTER DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE {$collation}";
        if ($dryRun) {
            printf("DRY RUN - Would run: %s", htmlspecialchars($alterDbSql, ENT_QUOTES, 'UTF-8'));
            echo '<br/>';
        } else {
            if (!$mysqli->query($alterDbSql)) {
                printf("Error altering database %s: %s", $db, $mysqli->error);
                echo '<br/>';
                    logAction(sprintf('Error altering database %s: %s', $db, $mysqli->error));
                continue;
            }
            logAction('Executed database SQL: ' . $alterDbSql);
        }
        foreach ($tables as $tb) {
            $alterTableSql = "ALTER TABLE `{$db}`.`{$tb}` CONVERT TO CHARACTER SET utf8mb4 COLLATE {$collation}";
            printf("  Converting table: %s", $tb);
            echo '<br/>';
                 if ($dryRun) {
                     printf("  DRY RUN - Would run: %s", htmlspecialchars($alterTableSql, ENT_QUOTES, 'UTF-8'));
                     echo '<br/>';
            } else {
                if (!$mysqli->query($alterTableSql)) {
                    printf("  Error converting table %s.%s: %s", $db, $tb, $mysqli->error);
                    echo '<br/>';
                    logAction(sprintf('Error converting table %s.%s: %s', $db, $tb, $mysqli->error));
                } else {
                    logAction('Executed table SQL: ' . $alterTableSql);
                }
            }
        }
    }
    echo "<br/>Completed<br/>";
}

$result->free();
$mysqli->close();

