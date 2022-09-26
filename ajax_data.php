<?php
/*
 * Page to handle the AJAX requests to migrate batches of records.
 * Given the high volume of record imports this was designed to handle (10's of thousands, 100 records at a time), trying to minimize
 * the overhead and processing time by not needing to call the module object and doing whatever setup possible outside
 * of this page.
 * This would be point of trying to reduce runtime, and there is possible wiggle room of handling more records in a single run
 * of this page (higher difference in $recordStartCount and $recordStopCount) if it doesn't cause issues in memory use. Telling this
 * page to process more records at a time does save runtime over the course of all records being migrated.
 * */

$tableHTML = "";

// Configuration settings are stored as a session variable so that the configuration doesn't have to be passed or processed every time this is called
if (isset($_SESSION['move_record_config'])) {
    $loadedConfig = $_SESSION['move_record_config']; // The configuration settings that defines how migrations should happen
    $projLoopCount = $_POST['project_count']; // Which pair of projects being migrated that we're on
    $recordStartCount = $_POST['record_start']; // The number of record in the list to start this migration
    $recordStopCount = $_POST['record_stop']; // The number of record in the list to stop this migration
    $configData = $loadedConfig['data']; // Get the mappings for this configuration

    // Make sure that a record mapping actually exists at starting index indicated. We need to stop the AJAX calls otherwise.
    if (isset($configData[$projLoopCount]) && isset($configData[$projLoopCount]['records'][$recordStartCount][0]) && isset($configData[$projLoopCount]['records'][$recordStartCount][1])) {
        $sourceProjectID = $configData[$projLoopCount]['projects'][0];
        $destProjectID = $configData[$projLoopCount]['projects'][1];

        // Make sure that projects are defined to migrate from and to, or in case of the renaming records process, just make sure that the first project ID is defined
        if (is_numeric($sourceProjectID) && (is_numeric($destProjectID) || $configData[$projLoopCount]['behavior'] == "rename")) {
            $events = $configData[$projLoopCount]['events'];
            $fields = $configData[$projLoopCount]['fields'];
            $instances = $configData[$projLoopCount]['instances'];
            $dags = $configData[$projLoopCount]['dags'];
            $behavior = $configData[$projLoopCount]['behavior'];
            $records = $configData[$projLoopCount]['records'];

            $recordList = array();
            for ($i = $recordStartCount; $i < $recordStopCount; $i++) {
                // Make sure as progressing from the start to end loop index that the record mappings continue to exist
                if (isset($configData[$projLoopCount]['records'][$i][0]) && isset($configData[$projLoopCount]['records'][$i][1])) {
                    $sourceRecord = $configData[$projLoopCount]['records'][$i][0];
                    $destRecord = $configData[$projLoopCount]['records'][$i][1];
                    $recordList[$sourceRecord] = $destRecord;
                }
            }
            if (!empty($recordList)) {
                // Determine whether we are only renaming records or migrating them
                if ($behavior == "rename") {
                    $tableHTML .= renameRecordList($sourceProjectID, $recordList);
                }
                else {
                    $tableHTML .= processRecordMigration($sourceProjectID, $destProjectID, $recordList, $fields, $events, $instances, $dags, $behavior);
                }
            }
        }
        // Just stop the looping if the projects are not defined properly
        else {
            $tableHTML = "stop!!!!";
        }
    }
    else {
        $tableHTML = "stop!!!!";
    }
}
// The output of this page should be whatever messages needed to track progress and communicate that progress to the user
echo $tableHTML;

/*
 * Function to copy over edoc files when a record is migrated. The process cannot be done by simply saving the edoc ID to another
 * record in another project.
 * @param int $project_id
 * @param int $edocId
 * @return array
 * */
function copyEdoc($pid, $edocId)
{
    if(empty($edocId)){
        // The stored id is already empty.
        return '';
    }

    // Only need to consider edocs that haven't been deleted by the server
    $sql = "select * from redcap_edocs_metadata where doc_id = ? and date_deleted_server is null";
    $result = \ExternalModules\ExternalModules::query($sql, [$edocId]);
    $row = $result->fetch_assoc();

    if(!$row){
        return '';
    }

    $row = \ExternalModules\ExternalModules::convertIntsToStrings($row);
    $oldPid = $row['project_id'];
    if($oldPid === $pid){
        // This edoc is already associated with this project.  No need to recreate it.
        $newEdocId = $edocId;
    }
    else{
        // Method within REDCap that handles file copying
        $newEdocId = copyFile($edocId, $pid);
    }

    return [
        $oldPid,
        (string)$newEdocId // We must cast to a string to avoid an issue on the js side when it comes to handling file fields if stored as integers.
    ];
}

/*
 * Function to copy over edoc files when a record is migrated. The process cannot be done by simply saving the edoc ID to another
 * record in another project.
 * @param int $project_id
 * @param int $edocId
 * @return array
 * */
function processRecordMigration($sourceProjectID,$destProjectID,$recordList,$fieldMapping,$eventMapping,$instanceMapping,$dagMapping,$behavior) {
    $sourceProject = new \Project($sourceProjectID);
    $destProject = new \Project($destProjectID);

    // Get the data from the source project. To speed this up, filter down the returned information as much as possible.
    // The data access groups flag is required to get DAG info about records.
    $sourceData = \Records::getData(array(
        'return_format' => 'array', 'fields' => array_keys($fieldMapping), 'project_id' => $sourceProject->project_id,
        'events' => array_keys($eventMapping), 'records'=> array_keys($recordList), 'exportDataAccessGroups' => true,
        'returnBlankForGrayFormStatus' => true
    ));

    $transferData = array();

    // Loop through record array data to convert it to the data formatting for the destination project.
    foreach ($sourceData as $recordID => $eventData) {
        if (!isset($recordList[$recordID])) continue;
        $destRecord = $recordList[$recordID];

        foreach ($eventData as $eventID => $fieldData) {
            // Account for any possible repeating instruments/events in the REDCap project
            if ($eventID == "repeat_instances") {
                foreach ($fieldData as $subEventID => $instrumentData) {
                    // Skip this event if it's not part of the event mapping
                    if (!isset($eventMapping[$subEventID])) continue;
                    foreach ($instrumentData as $instrumentName => $instanceData) {
                        foreach ($instanceData as $instanceID => $subFieldData) {
                            // Skip this instance if the instance mapping is defined and this instance is not contained in it
                            if (!empty($instanceMapping) && !isset($instanceMapping[$instanceID])) continue;
                            foreach ($subFieldData as $subFieldName => $subFieldValue) {
                                if (isset($fieldMapping[$subFieldName])) {
                                    // The record ID field specifically needs to be changed by definition of the config file
                                    if ($subFieldName == $destProject->table_pk) $subFieldValue = $destRecord;
                                    // Need to specifically set the new data access group based on the mapping between projects
                                    if ($subFieldName == "redcap_data_access_group" && isset($dagMapping[$subFieldValue])) $subFieldValue = $dagMapping[$subFieldValue];
                                    // File fields need their own process for duplication. Involves copying the file.
                                    if ($sourceProject->metadata[$subFieldName]['element_type'] == "file" && $subFieldValue != "") {
                                        list ($oldPID, $copyEdoc) = copyEdoc($destProjectID,$subFieldValue);
                                        $transferData[$destRecord][$eventID][$eventMapping[$subEventID]][$instrumentName][(!empty($instanceMapping) ? $instanceMapping[$instanceID] : $instanceID)][$fieldMapping[$subFieldName]] = $copyEdoc;
                                    }
                                    else {
                                        if ($sourceProject->metadata[$subFieldName]['element_enum'] != "" && $subFieldValue != "") {
                                            $validValues = parseEnum($sourceProject->metadata[$subFieldName]['element_enum']);

                                            if (is_array($subFieldValue) && !empty($subFieldValue)) {
                                                foreach ($subFieldValue as $enumValue => $setValue) {
                                                    if (!isset($validValues[$enumValue])) {
                                                        unset($subFieldValue[$enumValue]);
                                                    }
                                                }
                                            }
                                            else {
                                                if (!isset($validValues[$subFieldValue])) {
                                                    $subFieldValue = "";
                                                }
                                            }
                                        }
                                        $transferData[$destRecord][$eventID][$eventMapping[$subEventID]][$instrumentName][(!empty($instanceMapping) ? $instanceMapping[$instanceID] : $instanceID)][$fieldMapping[$subFieldName]] = $subFieldValue;
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                if (!isset($eventMapping[$eventID])) continue;
                foreach ($fieldData as $fieldName => $fieldValue) {
                    if (isset($fieldMapping[$fieldName])) {
                        // The record ID field specifically needs to be changed by definition of the config file
                        if ($fieldName == $destProject->table_pk) $fieldValue = $destRecord;
                        // Need to specifically set the new data access group based on the mapping between projects
                        if ($fieldName == "redcap_data_access_group" && isset($dagMapping[$fieldValue])) $fieldValue = $dagMapping[$fieldValue];
                        // File fields need their own process for duplication. Involves copying the file.
                        if ($sourceProject->metadata[$subFieldName]['element_type'] == "file" && $fieldValue != "") {
                            list ($oldPID, $copyEdoc) = copyEdoc($destProjectID,$fieldValue);
                            $transferData[$destRecord][$eventMapping[$eventID]][$fieldMapping[$fieldName]] = $copyEdoc;
                        }
                        else {
                            if ($sourceProject->metadata[$fieldName]['element_enum'] != "" && $fieldValue != "") {
                                $validValues = parseEnum($sourceProject->metadata[$fieldName]['element_enum']);

                                if (is_array($fieldValue) && !empty($fieldValue)) {
                                    foreach ($fieldValue as $enumValue => $setValue) {
                                        if (!isset($validValues[$enumValue])) {
                                            unset($fieldValue[$enumValue]);
                                        }
                                    }
                                }
                                else {
                                    if (!isset($validValues[$fieldValue])) {
                                        $fieldValue = "";
                                    }
                                }
                            }
                            $transferData[$destRecord][$eventMapping[$eventID]][$fieldMapping[$fieldName]] = $fieldValue;
                        }
                    }
                }
            }
        }
    }

    $results = \Records::saveData(array(
        'project_id'=>$destProject->project_id,'dataFormat'=>'array','data'=>$transferData,'overwriteBehavior'=>'overwrite','skipFileUploadFields'=>false
    ));

    $result = "";

    // Need to log if any errors occur during migration
    if (!empty($results['errors'])) {
        $result .= "There was an error migrating records in batch ".array_key_first($recordList)." to ".array_key_last($recordList).", following errors provided: ".(is_array($results['errors']) ? implode(", ",$results['errors']) : $results['errors'])."<br/>";
    }
    // If the ids list is empty then records weren't migrated
    elseif (empty($results['ids'])) {
        $result .= "Record migration was unsuccessful for records ".array_key_first($recordList)." to ".array_key_last($recordList)."<br/>";
    }
    else {
        // Loop through record IDs that were saved and track them in logging
        foreach ($results['ids'] as $destRecord) {
            $checking = \REDCap::getData(array('project_id' => $destProject->project_id, 'records' => array($destRecord), 'fields' => array($destProject->table_pk), 'return_format' => 'array'));
            if (isset($checking[$destRecord])) {
                // If set to delete original record upon migration, verify it exists then delete the original
                if ($behavior == "delete" && empty($results['errors']) && $results['ids'][$destRecord] == $destRecord) {
                    //TODO Is the check for record existing necessary or does it use too much processing time??
                    $deletion = \REDCap::deleteRecord($sourceProject->project_id, $recordID);
                }
            }
            else {
                $result .= "Record $destRecord was not created in project $destProjectID<br/>";
            }
        }
    }

    return ($result != "" ? $result : "");
}

/*
 * Function to loop through a list of records to rename them. Returns result of the renaming process.
 * @param int $project_id
 * @param array $recordList
 * @return string
 * */
function renameRecordList($project_id, $recordList) {
    $returnString = "";

    if (is_array($recordList)) {
        foreach ($recordList as $oldRecord => $newRecord) {
            // The renameRecord function
            if (!\REDCap::renameRecord($project_id,$oldRecord,$newRecord)) {
                $returnString .= "There was an issue renaming record $oldRecord to $newRecord<br/>";
            }
        }
    }

    return $returnString;
}
?>