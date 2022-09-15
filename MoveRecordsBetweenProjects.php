<?php
namespace Vanderbilt\MoveRecordsBetweenProjects;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

class MoveRecordsBetweenProjects extends AbstractExternalModule
{
    function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance = 1) {
        /*$sourceData = \Records::getData(array(
            'return_format' => 'array','project_id' => $project_id,'returnBlankForGrayFormStatus'=>true,
            'records'=> array($record), 'exportDataAccessGroups' => true
        ));
        echo "<pre>";
        print_r($sourceData);
        echo "</pre>";*/
    }

    /*
     * Formats a configuation file into an array for mapping between source and destination projects
     * @param $config
     * @return array
     */
    function processConfiguration($config) {
        $result = array();

        // Make sure that the configuration file is valid and provide an error message if not
        if (!$this->validConfiguration($config)) {
            $result['errors'][] = "Migration Error: A valid configuration was not provided. Please make sure there are at least a 'Projects' and 'Records' section to your CSV file.";
        }
        else {
            // Loop through projects in the configuration file and create the mappings for their various properties.
            foreach ($config['projects'] as $index => $projects) {
                list($records,$fields,$events,$instances,$dags,$behavior) = $this->getDefaultSettings($config,$index);
                $sourceProject = new \Project($projects[0]);
                $destProject = new \Project($projects[1]);

                // Turn the configuration file into a list of arrays that are usable in the migration process.
                $eventMapping = $this->mapEvents($sourceProject,$destProject,$events);
                $fieldMapping = $this->mapFields($sourceProject,$destProject,$fields);
                $dagMapping = $this->mapDAGs($sourceProject,$destProject,$dags);
                $instanceMapping = $this->mapInstances($instances);

                // Ordering the mappings into an array to return.
                $result['data'][$index] = array('projects'=>$projects,'records'=>$records,'events'=>$eventMapping,'fields'=>$fieldMapping,'instances'=>$instanceMapping,'dags'=>$dagMapping,'behavior'=>$behavior);
            }
        }
        return $result;
    }

    /*
     * Checks the configuration file for the necessary sections of projects and records
     * @param string $config
     * @return boolean
     */
    function validConfiguration($config) {
        return (is_array($config['projects']) && is_array($config['records']));
    }

    /*
     * For a given configuration file project pairing, get the default values for the various sections. Sections with defaults if empty are events,instances,dags,behavior,fields.
     * @param string $config
     * @param int $index
     * @return array
     */
    function getDefaultSettings($config,$index) {
        $records = $config['records'][$index]; // Required setting for a valid configuration, will check that it isn't empty/invalid elsewhere

        // These settings are optional. By default they are empty and the process for each generates a default mapping
        $events = (is_array($config['events'][$index]) ? $config['events'][$index] : array());
        $instances = (is_array($config['instances'][$index]) ? $config['instances'][$index] : array());
        $dags = (is_array($config['dags'][$index]) ? $config['dags'][$index] : array());
        $fields = (is_array($config['fields'][$index]) ? $config['fields'][$index] : array());

        // Optional parameter for how records should be migrated, defaults that the original record should be deleted on migration
        $behavior = (is_array($config['behavior'][$index]) ? $config['behavior'][$index][0][0] : "delete");
        return array($records,$fields,$events,$instances,$dags,$behavior);
    }

    /*
     * Creating a mapping of the event IDs of the source project to the destination project for migration. If the settings defines this mapping, that is used. If the event mappings are empty, defaults to mapping events by arm number and event order.
     * @param Project $sourceProject
     * @param Project $destProject
     * @param array $settings
     * @return array
     */
    function mapEvents(\Project $sourceProject, \Project $destProject,$settings) {
        $returnArray = array();

        // Getting event IDs, maps arm numbers to event IDs, because arm numbers are needed for other processes
        $sourceEvents = $this->getEventIds($sourceProject->project_id);
        $destEvents = $this->getEventIds($destProject->project_id);

        if (is_array($settings) && !empty($settings)) {
            foreach ($settings as $events) {
                // Make sure the events specified in the events settings actually exist in the REDCap projects
                if (in_array($events[0],$sourceEvents) && in_array($events[1],$destEvents)) {
                    $returnArray[$events[0]] = $events[1];
                }
            }
        }
        else {
            foreach ($sourceEvents as $armNum => $source) {
                // Default behavior maps events based on their arm number and their order
                if (isset($destEvents[$armNum])) {
                    foreach ($source as $index => $eventID) {
                        if (!isset($destEvents[$armNum][$index])) continue;
                        $returnArray[$eventID] = $destEvents[$armNum][$index];
                    }
                }
            }
        }

        return $returnArray;
    }

    /*
     * Creating a mapping of the fields of the source project to the destination project for migration. If the settings defines this mapping, that is used. If the field mappings are empty, defaults to mapping fields by their names and only if their field definitions match (ex: radio field to radio field with the same options).
     * @param Project $sourceProject
     * @param Project $destProject
     * @param array $settings
     * @return array
     */
    function mapFields(\Project $sourceProject, \Project $destProject, $settings) {
        $returnArray = array();
        $sourceFields = $sourceProject->metadata;
        $destFields = $destProject->metadata;

        if (is_array($settings) && !empty($settings)) {
            foreach ($settings as $fields) {
                // Make sure that the fields specified in the configuration settings actually exist in each project,
                // and that they can be paired by validation type and enum values
                if (isset($sourceFields[$fields[0]]) && isset($destFields[$fields[1]]) && $this->matchingFields($sourceFields[$fields[0]],$destFields[$fields[1]])) {
                    $returnArray[$fields[0]] = $fields[1];
                }
            }
        }
        else {
            foreach ($sourceFields as $fieldName => $sMeta) {
                $dMeta = $destFields[$fieldName];
                // The default process matches fields based on havin the same name in both projects, and that they are the same type of field
                if (is_array($sMeta) && isset($dMeta) && $this->matchingFields($sMeta,$dMeta)) {
                    $returnArray[$fieldName] = $fieldName;
                }
            }
        }

        // We always need to have the field that designates the data access group for the record when saving it,
        // but the field doesn't exist in the project so it must be added manually
        if (!empty($returnArray) && !isset($returnArray['redcap_data_access_group'])) {
            $returnArray['redcap_data_access_group'] = 'redcap_data_access_group';
        }

        return $returnArray;
    }

    /*
     * Creating a mapping of the data access groups of the source project to the destination project for migration. If the settings defines this mapping, that is used. If the data access group mappings are empty, defaults to mapping them to each other based on their unique names.
     * @param Project $sourceProject
     * @param Project $destProject
     * @param array $settings
     * @return array
     */
    function mapDAGs(\Project $sourceProject, \Project $destProject, $settings) {
        $returnArray = array();
        // Get a list of the unique names for the data access groups in the two projects
        $sourceDAGs = $sourceProject->getUniqueGroupNames();
        $destDAGs = $destProject->getUniqueGroupNames();

        if (is_array($settings) && !empty($settings)) {
            foreach ($settings as $dags) {
                // When reading from settings, make sure that we're trying to use DAG names that actually exist in each project
                if (is_array($sourceDAGs) && is_array($destDAGs) && in_array($dags[0],$sourceDAGs) && in_array($dags[1],$destDAGs)) {
                    $returnArray[$dags[0]] = $dags[1];
                }
            }
        }
        else {
            foreach ($sourceDAGs as $srcID => $srcName) {
                // Default process matches data access groups that share unique names in each project
                if (is_array($destDAGs) && in_array($srcName,$destDAGs)) {
                    $returnArray[$srcName] = $srcName;
                }
            }
        }

        return $returnArray;
    }

    /*
     * Creating a mapping of the record instances of the source project to the destination project for migration. If the settings defines this mapping, that is used. If the instance mappings are empty, defaults to keeping instance numbering consistent across migration.
     * @param array $instanceList
     * @return array
     */
    function mapInstances($instanceList) {
        $returnArray = array();
        // Only need to set up instance mapping if the settings for it exists
        if (is_array($instanceList) && !empty($instanceList)) {
            foreach ($instanceList as $match) {
                $returnArray[$match[0]] = $match[1];
            }
        }
        return $returnArray;
    }

    /*
     * Runs a SQL statement to provide a mapping between arm numbers and event IDs in a REDCap project.
     * @param int $project_id
     * @return array
     */
    function getEventIds($project_id) {
        $eventArray = array();
        $sql = "select a.arm_id,a.arm_num as arm_num,a.project_id,e.event_id as event_id, e.day_offset from redcap_events_metadata e, redcap_events_arms a where a.project_id = ?
				and a.arm_id = e.arm_id order by a.arm_num,e.day_offset";
        $result = $this->query($sql,[$project_id]);
        $currentEventArm = "";
        $eventCount = 0 ;
        while ($row = $result->fetch_assoc()) {
            // A check being performed to monitor when a new event arm number is reached, and thus the event counter needs reset
            if ($currentEventArm == "" || $currentEventArm != $row['arm_num']) {
                $currentEventArm = $row['arm_num'];
                $eventCount = 0;
            }
            $eventArray[$row['arm_num']][$eventCount] = $row['event_id'];
            $eventCount++;
        }

        return $eventArray;
    }

    /*
     * Makes a determination of whether two fields share a validation type and, if required, enum values for options of the field.
     * @param array $srcMeta
     * @param array $destMeta
     * @return boolean
     */
    function matchingFields($srcMeta,$destMeta) {
        if ($srcMeta['element_type'] == $destMeta['element_type'] && $this->matchEnum($srcMeta['element_enum'],$destMeta['element_enum'])) {
            return true;
        }
        return false;
    }

    /*
     * Verify that the enum definitions of two fields are equal. Requires removing newline and space characters that don't affect the value of the options, but may be present.
     * @param array $srcEnum
     * @param array $destEnum
     * @return boolean
     */
    function matchEnum($srcEnum, $destEnum) {
        $destEnum = str_replace(' \n','\n',$destEnum);
        $destEnum = str_replace('\n ','\n',$destEnum);
        $destEnum = str_replace(' ,',',',$destEnum);
        $destEnum = str_replace(', ',',',$destEnum);
        $destEnum = rtrim($destEnum);
        $srcEnum = str_replace(' \n','\n',$srcEnum);
        $srcEnum = str_replace('\n ','\n',$srcEnum);
        $srcEnum = str_replace(' ,',',',$srcEnum);
        $srcEnum = str_replace(', ',',',$srcEnum);
        $srcEnum = rtrim($srcEnum);

        if ($srcEnum == $destEnum) {
            return true;
        }
        return false;
    }

    /*
     * Creating a mapping of the data access groups of the source project to the destination project for migration. If the settings defines this mapping, that is used. If the data access group mappings are empty, defaults to mapping them to each other based on their unique names.
     * @param int $moduleProjectID
     * @param int $sourceProjectID
     * @param int $destProjectID
     * @param string $behavior
     * @param string $startDateTime
     * @param string $endDateTime
     * @param string $userID
     * @param string $message
     */
    function logProcess($moduleProjectID,$sourceProjectID,$destProjectID,$behavior,$startDateTime,$endDateTime,$userID,$message) {
        $this->log("Move Records module performed data migration", [
            'moduleProjectID' => $moduleProjectID,
            'sourceProjectID' => $sourceProjectID,
            'destinationProjectID' => $destProjectID,
            'behavior' => $behavior,
            'startDateTime' => $startDateTime,
            'endDateTime' => $endDateTime,
            'user' => $userID,
            'logMessage' => $message
        ]);
    }

    /*
     * Pull the list of logs for this module created from the logProcess function
     * @param int $project_id
     * @return array
     */
    function loadModuleLogs($project_id = "") {
        $returnArray = array();
        $logs = $this->queryLogs("SELECT message,moduleProjectID,sourceProjectID,destinationProjectID,behavior,startDateTime,endDateTime,user,logMessage WHERE message='Move Records module performed data migration' ".(is_numeric($project_id) ? "AND moduleProjectID = '$project_id'" : ""));

        while ($row = db_fetch_assoc($logs)) {
            $returnArray[] = $row;
        }
        return array_reverse($returnArray);
    }
}