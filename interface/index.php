<?php
require_once APP_PATH_DOCROOT.'ProjectGeneral/header.php';

$project_id = $_GET['pid'];
$validSections = array("projects","events","instances","fields","records","behavior","dags");

if ($project_id != "" && is_numeric($project_id)) {
    $module = new \Vanderbilt\MoveRecordsBetweenProjects\MoveRecordsBetweenProjects($project_id);
    $configuration = $loadedConfig = array();

    if (isset($_FILES['import_file'])) {
        $tmp = $_FILES['import_file']['tmp_name'];
        $currentSection = "";

        if (($handle = fopen($tmp, 'r')) !== false) {
            $loopCount = 0;

            while (($data = fgetcsv($handle)) !== false) {
                if (in_array(strtolower(preg_replace('/[^A-Za-z0-9\-]/', '', $data[0])),$validSections)) {
                    $currentSection = strtolower(preg_replace('/[^A-Za-z0-9\-]/', '', $data[0]));
                    $loopCount = 0;
                }
                else {
                    for ($i = 0; $i < count($data); $i += 2) {
                        if (isset($data[$i]) && isset($data[$i+1])) {
                            if ($currentSection == "projects") {
                                $configuration[$currentSection][$i % 2] = array($data[$i], $data[$i + 1]);
                            }
                            else {
                                $configuration[$currentSection][$i % 2][$loopCount] = array($data[$i], $data[$i + 1]);
                            }
                        }
                    }
                    $loopCount++;
                }
            }
            fclose($handle);
        }
    }

    if (!empty($configuration)) {
        $loadedConfig = $module->processConfiguration($project_id, $configuration);
    }
    /*echo "<pre>";
    print_r($loadedConfig);
    echo "</pre>";*/

    $logs = $module->loadModuleLogs($project_id);

    $loggingURL = APP_PATH_WEBROOT_FULL."redcap_v".REDCAP_VERSION."/Logging/index.php";

    echo "<div id='move_dashboard'>
        <form enctype='multipart/form-data' action='".$module->getUrl('interface/index.php')."' method='post' id='import_settings_form' name='import_settings_form'>
            <span><h5 style='color:red;text-align:center'>It is recommended that you back up the data in the projects involved before performing migration.</h5></span>
            <div id='ui_elements'>
                <input type='file' name='import_file' accept='.csv' />
                <input type='submit' name='import_submit' value='Submit' />
            </div>
        </form>
        <div id='move_results'></div>
        <div id='module_logs'>";
    echo "<table class='module-report-table'><tr><th>Source Project</th><th>Destination Project</th><th>User</th><th>Behavior</th><th>Start Process Time</th><th>End Process Time</th><th>Message</th></tr>";
        foreach ($logs as $log) {
            $startDateTime = new DateTime($log['startDateTime']);
            $startDateTime->modify('-30 minute');
            $endDateTime = new DateTime($log['endDateTime']);
            $endDateTime->modify('+30 minute');
            $loggingURL .= "?beginTime=".$startDateTime->format('m-d-Y H:i')."&endTime=".$endDateTime->format('m-d-Y H:i')."&usr=".$log['user'];
            echo "<tr><td>".$log['sourceProjectID']."<br/><a target='_blank' href='".$loggingURL."&pid=".$log['sourceProjectID']."'>View Logging</a></td><td>".$log['destinationProjectID']."<br/><a target='_blank' href='".$loggingURL."&pid=".$log['destinationProjectID']."'>View Logging</a></td><td>".$log['user']."</td><td>".$log['behavior']."</td><td>".$log['startDateTime']."</td><td>".$log['endDateTime']."</td><td>".$log['logMessage']."</td></tr>";
        }
        echo "</table>";
        echo "</div>
    </div>";
}
?>
<style>
    table.module-report-table td,th {
        border: 1px solid black;
        text-align:center;
    }
    table.module-report-table {
        width:100%;
    }
</style>
<script>
    let migrateConfig = <?php echo json_encode($loadedConfig); ?>;

    function migrateRecord(recordCount,projects,records,events,fields,instances,dags,behavior) {
        $.ajax({
            url: '<?php echo $module->getUrl('ajax_data.php'); ?>',
            method: 'post',
            data: {
                projects: projects,
                records: records[recordCount],
                events: events,
                fields: fields,
                instances: instances,
                behavior: behavior
            },
            success: function (html) {
                console.log(html);
                recordCount++;
                (records[recordCount] !== undefined && migrateRecord(recordCount,projects,records,events,fields,instances,behavior));
            }
        });
    }

    $(document).ready(function() {
        if (Array.isArray(migrateConfig['data'])) {
            let configData = migrateConfig['data'];
            for (var i = 0; i < configData.length; i++) {
                migrateRecord(0,configData[i]['projects'],configData[i]['records'],configData[i]['events'],configData[i]['fields'],configData[i]['instances'],configData[i]['dags'],configData[i]['behavior'])
            }
        }
    });
</script>
