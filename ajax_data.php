<?php

if (!empty($_POST)) {
    $sourceProjectID = $_POST['projects'][0];
    $destProjectID = $_POST['projects'][1];
    if (is_numeric($sourceProjectID) && is_numeric($destProjectID)) {
        $events = $_POST['events'];
        $fields = $_POST['fields'];
        $instances = $_POST['instances'];
        $dags = $_POST['dags'];
        $behavior = $_POST['behavior'];
        $sourceRecord = $_POST['records'][0];
        $destRecord = $_POST['records'][1];
        $module = new \Vanderbilt\MoveRecordsBetweenProjects\MoveRecordsBetweenProjects();

        $results = $module->processRecordMigration($sourceProjectID,$destProjectID,$sourceRecord,$destRecord,$fields,$events,$instances,$dags,$behavior);
        echo "<pre>";
        print_r($results);
        echo "</pre>";
    }
}

