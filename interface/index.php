<?php
/*
 * File that provides the interface for loading a configuration CSV file to begin record migration.
 * Provides a read out of logs of past migrations to allow users to navigate to the logs of previous record migrations.
 * */

require_once APP_PATH_DOCROOT.'ProjectGeneral/header.php';

// Any time this page is loaded, the project it is running on will be part of the URL
$project_id = $_GET['pid'];
// List of valid sections allowed in the CSV configuration file
$validSections = array("projects","events","instances","fields","records","behavior","dags");

// Only load the page if the project ID provided is a number and is set
if ($project_id != "" && is_numeric($project_id)) {
    $module = new \Vanderbilt\MoveRecordsBetweenProjects\MoveRecordsBetweenProjects($project_id);
    $configuration = $loadedConfig = array();

    // Process to read through uploaded CSV configuration file
    if (isset($_FILES['import_file'])) {
        $tmp = $_FILES['import_file']['tmp_name'];
        $currentSection = "";

        if (($handle = fopen($tmp, 'r')) !== false) {
            $loopCount = 0;

            while (($data = fgetcsv($handle)) !== false) {
                // Check for new entry into one of the valid sections of the configuration file
                // All of the regex checks are because Microsoft Excel can place random non-alphanumeric characters at the start of a CSV file
                if (in_array(strtolower(preg_replace('/[^A-Za-z0-9\-]/', '', $data[0])),$validSections)) {
                    $currentSection = strtolower(preg_replace('/[^A-Za-z0-9\-]/', '', $data[0]));
                    $loopCount = 0;
                }
                else {
                    for ($i = 0; $i < count($data); $i += 2) {
                        // Make sure both columns needed for a valid mapping have a value
                        if (isset($data[$i]) && isset($data[$i+1])) {
                            // Project mapping are different since they are a single setting per migration, where all others are many
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

    // Turn the configuration file data into mappings for use elsewhere
    if (!empty($configuration)) {
        $loadedConfig = $module->processConfiguration($configuration);
    }

    // Due to the vast number of records that may need migrating, use session variables to carry the configuration settings instead
    // of needing to parse and read it again every time a new AJAX migration call is made
    $_SESSION['move_record_config'] = $loadedConfig;

    $logs = $module->loadModuleLogs($project_id);

    // Base level URL of the logging page without the various project values
    $loggingURL = APP_PATH_WEBROOT_FULL."redcap_v".REDCAP_VERSION."/Logging/index.php";

    /*
     * DIV Explanations:
     * 'move_progress_status' = Displays a percentage completion bar when processing an uploaded migration file
     * 'move_results' = Displays a running log of the results of the current migration. Currently reworking what data goes into this
     * 'module_logs' = Displays a table of the logs of previous migration processes that have been run from the current REDCap project
     * */
    echo "<div id='move_dashboard'>
        <form enctype='multipart/form-data' action='".$module->getUrl('interface/index.php')."' method='post' id='import_settings_form' name='import_settings_form'>
            <span><h5 style='color:red;text-align:center'>It is recommended that you back up the data in the projects involved before performing migration.</h5></span>
            <div id='ui_elements'>
                <input type='file' name='import_file' accept='.csv' />
                <input type='submit' name='import_submit' value='Submit' />
            </div>
        </form>
        <div id='move_progress_status'><div id='move_progress'></div></div>
        <div id='move_results'>";
        if (!empty($loadedConfig['errors'])) {
            echo implode("<br/>",$loadedConfig['errors']);
        }
        echo "</div>
        <div id='module_logs'>";
    echo "<table class='module-report-table'><tr><th>Source Project</th><th>Destination Project</th><th>User</th><th>Behavior</th><th>Start Process Time</th><th>End Process Time</th><th>Message</th></tr>";
        foreach ($logs as $log) {
            // Linking to the Logging page for the affected REDCap project. Provides some filters to try to narrow down to the
            // approximate timeframe and situation to make sure it loads for projects with huge amounts of logging.
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
    let currentdate = new Date();
    let startTime = currentdate.getDate() + '-'
        + (currentdate.getMonth()+1)  + '-'
        + currentdate.getFullYear() + ' '
        + currentdate.getHours() + ':'
        + currentdate.getMinutes() + ':'
        + currentdate.getSeconds();
    <?php
        // If processing an uploaded configuration file, run the Javascript to start the AJAX process
    if (!empty($loadedConfig) && empty($loadedConfig['errors'])) {
        echo "let totalMigrations = ".count($loadedConfig['data']['0']['records']).";
        $(document).ready(function () {
            console.log('Start Time: '+Date.now());    
            migrateRecord(0, 0, 100);
        });";
    }
    elseif (!empty($loadedConfig['errors'])) {
        echo "currentdate = new Date(); 
        let endTime = currentdate.getDate() + '-'
                + (currentdate.getMonth()+1)  + '-' 
                + currentdate.getFullYear() + ' '  
                + currentdate.getHours() + ':'  
                + currentdate.getMinutes() + ':' 
                + currentdate.getSeconds();
        storeLogging(startTime,endTime);";
    }
    ?>
    // Function to handle batches of record migrations. Self-referencing in a loop as it migrates records in groups.
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
                // When the AJAX page has reached the end of records to migrate, it passes the magic phrase to end the loop
                if (html != "stop!!!!") {
                    if (html != "") {
                        $('#move_results').prepend(html);
                    }
                    // Increment the loop to the next batch of records
                    let recordCount = parseInt(recordStart) + parseInt(stepCount) + 1;
                    update_progress(recordCount);
                    migrateRecord(projectCount,recordCount,stepCount);
                }
                else {
                    currentdate = new Date();
                    let endTime = currentdate.getDate() + '-'
                        + (currentdate.getMonth()+1)  + '-'
                        + currentdate.getFullYear() + ' '
                        + currentdate.getHours() + ':'
                        + currentdate.getMinutes() + ':'
                        + currentdate.getSeconds();
                    storeLogging(startTime,endTime);
                    console.log("No more loops!");
                    console.log('End Time: '+Date.now());
                }
            }
        });
    }

    // Handles updating the progress bar as records are migrated to let the user know how far the process has gone
    function update_progress(currentStep) {
        var element = document.getElementById("move_progress");
        var mainProgressDiv = document.getElementById("move_progress_status");
        mainProgressDiv.style.display = 'block';
        var width = parseFloat((parseFloat(currentStep) / parseFloat(totalMigrations)) * 100).toFixed(2);

        if (width >= 100) {
            mainProgressDiv.style.display = 'none';
            element.style.width = '0%';
            element.innerHTML = '0%';
        } else {
            element.style.width = width + '%';
            element.innerHTML = width + '%';
        }
    }

    function storeLogging(startTime,endTime) {
        let logElement = document.getElementById('move_results');

        $.ajax({
            url: '<?php echo $module->getUrl('create_logs.php'); ?>',
            method: 'post',
            data: {
                'log_html': logElement.innerHTML,
                'start_time': startTime,
                'end_time': endTime
            },
            success: function (html) {
                console.log(html);
            }
        });
    }
</script>
