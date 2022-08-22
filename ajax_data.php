<?php

$tableHTML = "";
if (isset($_SESSION['move_record_config'])) {
    $loadedConfig = $_SESSION['move_record_config'];
    $projLoopCount = $_POST['project_count'];
    $recordStartCount = $_POST['record_start'];
    $recordStopCount = $_POST['record_stop'];
    $configData = $loadedConfig['data'];
    if (isset($configData[$projLoopCount]) && isset($configData[$projLoopCount]['records'][$recordStartCount][0]) && isset($configData[$projLoopCount]['records'][$recordStartCount][1])) {
        $sourceProjectID = $configData[$projLoopCount]['projects'][0];
        $destProjectID = $configData[$projLoopCount]['projects'][1];

        if (is_numeric($sourceProjectID) && is_numeric($destProjectID)) {
            $events = $configData[$projLoopCount]['events'];
            $fields = $configData[$projLoopCount]['fields'];
            $instances = $configData[$projLoopCount]['instances'];
            $dags = $configData[$projLoopCount]['dags'];
            $behavior = $configData[$projLoopCount]['behavior'];
            $records = $configData[$projLoopCount]['records'];

            $recordList = array();
            for ($i = $recordStartCount; $i < $recordStopCount; $i++) {
                if (isset($configData[$projLoopCount]['records'][$i][0]) && isset($configData[$projLoopCount]['records'][$i][1])) {
                    $sourceRecord = $configData[$projLoopCount]['records'][$i][0];
                    $destRecord = $configData[$projLoopCount]['records'][$i][1];
                    $recordList[$sourceRecord] = $destRecord;
                }
            }
            if (!empty($recordList)) {
                $tableHTML .= processRecordMigration($sourceProjectID, $destProjectID, $recordList, $fields, $events, $instances, $dags, $behavior);
            }
        }
        else {
            $tableHTML = "stop!!!!";
        }
    }
    else {
        $tableHTML = "stop!!!!";
    }
}
echo $tableHTML;

function processRecordMigration($sourceProjectID,$destProjectID,$recordList,$fieldMapping,$eventMapping,$instanceMapping,$dagMapping,$behavior) {
    //TODO What happens in this if a file field is trying to be moved??
    $sourceProject = new \Project($sourceProjectID);
    $destProject = new \Project($destProjectID);

    $sourceData = \Records::getData(array(
        'return_format' => 'array', 'fields' => array_keys($fieldMapping), 'project_id' => $sourceProject->project_id,
        'events' => array_keys($eventMapping), 'records'=> array_keys($recordList), 'exportDataAccessGroups' => true
    ));

    $transferData = array();
    foreach ($sourceData as $recordID => $eventData) {
        if (!isset($recordList[$recordID])) continue;
        $destRecord = $recordList[$recordID];

        foreach ($eventData as $eventID => $fieldData) {
            if ($eventID == "repeat_instances") {
                foreach ($fieldData as $subEventID => $instrumentData) {
                    if (!isset($eventMapping[$subEventID])) continue;
                    foreach ($instrumentData as $instrumentName => $instanceData) {
                        foreach ($instanceData as $instanceID => $subFieldData) {
                            if (!empty($instanceMapping) && !isset($instanceMapping[$instanceID])) continue;
                            foreach ($subFieldData as $subFieldName => $subFieldValue) {
                                if (isset($fieldMapping[$subFieldName])) {
                                    if ($subFieldName == $destProject->table_pk) $subFieldValue = $destRecord;
                                    if ($subFieldName == "redcap_data_access_group" && isset($dagMapping[$subFieldValue])) $subFieldValue = $dagMapping[$subFieldValue];
                                    $transferData[$destRecord][$eventID][$eventMapping[$subEventID]][$instrumentName][(!empty($instanceMapping) ? $instanceMapping[$instanceID] : $instanceID)][$fieldMapping[$subFieldName]] = $subFieldValue;
                                }
                            }
                        }
                    }
                }
            } else {
                if (!isset($eventMapping[$eventID])) continue;
                foreach ($fieldData as $fieldName => $fieldValue) {
                    if (isset($fieldMapping[$fieldName])) {
                        if ($fieldName == $destProject->table_pk) $fieldValue = $destRecord;
                        if ($fieldName == "redcap_data_access_group" && isset($dagMapping[$fieldValue])) $fieldValue = $dagMapping[$fieldValue];
                        $transferData[$destRecord][$eventMapping[$eventID]][$fieldMapping[$fieldName]] = $fieldValue;
                    }
                }
            }
        }
    }
    $results = \Records::saveData(array(
        'project_id'=>$destProject->project_id,'dataFormat'=>'array','data'=>$transferData,'overwriteBehavior'=>'overwrite'
    ));
    $result = "";

    if (!empty($results['errors'])) {
        $result .= "There was an error migrating records, following errors provided: ".var_dump($results['errors'])."<br/>";
    }
    elseif (empty($results['ids'])) {
        $result .= "Record migration was unsuccessful for records: ".var_dump(array_keys($sourceData));
    }
    else {
        foreach ($results['ids'] as $destRecord) {
            $checking = \REDCap::getData(array('project_id' => $destProject->project_id, 'records' => array($destRecord), 'fields' => array($destProject->table_pk), 'return_format' => 'array'));
            if (isset($checking[$destRecord])) {
                $result .= "Migration of $recordID to $destRecord was successful<br/>";
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

    return ($result != "" ? $result."<br/>" : "");
}