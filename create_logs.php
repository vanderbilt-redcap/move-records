<?php

$project_id = $_GET['pid'];
// List of valid sections allowed in the CSV configuration file
$validSections = array("projects","events","instances","fields","records","behavior","dags");

// Only load the page if the project ID provided is a number and is set
if ($project_id != "" && is_numeric($project_id)) {
    $module = new \Vanderbilt\MoveRecordsBetweenProjects\MoveRecordsBetweenProjects($project_id);
    $loggingHTML = str_replace(array("<br />","<br/>"),"<br>",$_POST['log_html']);
    $loadedConfig = $_SESSION['move_record_config']; // The configuration settings that defines how migrations should happen
    $startLog = $_POST['start_time'];
    $endLog = $_POST['end_time'];

    $configData = $loadedConfig['data']; // Get the mappings for this configuration

    // Make sure that a record mapping actually exists at starting index indicated. We need to stop the AJAX calls otherwise.
    foreach ($configData as $projLoopCount => $pConfig) {
        $sourceProjectID = $pConfig['projects'][0];
        $destProjectID = $pConfig['projects'][1];
        if (is_numeric($sourceProjectID) && (is_numeric($destProjectID) || $pConfig['behavior'] == "rename")) {
            $events = $pConfig['events'];
            $fields = $pConfig['fields'];
            $instances = $pConfig['instances'];
            $dags = $pConfig['dags'];
            $behavior = $pConfig['behavior'];
            $records = $pConfig['records'];
            $module->logProcess($project_id,$sourceProjectID,$destProjectID,$behavior,$startLog,$endLog,USERID,$loggingHTML);
        }
    }
}