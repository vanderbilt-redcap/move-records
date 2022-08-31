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
        $loadedConfig = $module->processConfiguration($configuration);
    }
    /*echo "<pre>";
    print_r($loadedConfig);
    echo "</pre>";*/
    $_SESSION['move_record_config'] = $loadedConfig;

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
        <div id='move_progress_status'><div id='move_progress'></div></div>
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
    #move_progress_status {
        width: 50%;
        background-color: #ddd;
        display:none;
    }
    #move_progress {
        width: 1%;
        height: 35px;
        background-color: #4CAF50;
        text-align: center;
        line-height: 32px;
        color: black;
    }

</style>
<script>
    <?php
    if (!empty($loadedConfig)) {
        echo "let totalMigrations = ".count($loadedConfig['data']['0']['records']).";
        $(document).ready(function () {
            console.log('Start Time: '+Date.now());    
            migrateRecord(0, 0, 100);
        });";
    }
    ?>
    function migrateRecord(projectCount,recordStart,stepCount) {
        console.time('Execution Time');
        $.ajax({
            url: '<?php echo $module->getUrl('ajax_data.php'); ?>',
            method: 'post',
            data: {
                'project_count': projectCount,
                'record_start': recordStart,
                'record_stop': (parseInt(recordStart) + parseInt(stepCount))
            },
            success: function (html) {
                console.log(html);
                console.timeEnd('Execution Time');
                if (html != "stop!!!!") {
                    //$('#move_results').prepend(html);
                    let recordCount = parseInt(recordStart) + parseInt(stepCount) + 1;
                    update_progress(recordCount);
                    migrateRecord(projectCount,recordCount,stepCount);
                }
                else {
                    console.log("No more loops!");
                    console.log('End Time: '+Date.now());
                }
            }
        });
    }
    function update_progress(currentStep) {
        var element = document.getElementById("move_progress");
        var mainProgressDiv = document.getElementById("move_progress_status");
        mainProgressDiv.style.display = 'block';
        var width = parseFloat((parseFloat(currentStep) / parseFloat(totalMigrations)) * 100).toFixed(2);
        console.log('Current: '+currentStep+', Total: '+totalMigrations);
        if (width >= 100) {
            mainProgressDiv.style.display = 'none';
            element.style.width = '0%';
            element.innerHTML = '0%';
        } else {
            element.style.width = width + '%';
            element.innerHTML = width + '%';
        }
    }
</script>
