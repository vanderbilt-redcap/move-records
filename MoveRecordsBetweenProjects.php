<?php
namespace Vanderbilt\MoveRecordsBetweenProjects;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use mysql_xdevapi\Exception;
use REDCap;

class MoveRecordsBetweenProjects extends AbstractExternalModule
{
    function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance = 1) {

    }

    /*
     * Formats a configuation file into an array for mapping between source and destination projects
     * @param $config
     * @return array
     */
    function processConfiguration($config) {
        $result = array();

        if (!$this->validConfiguration($config)) {
            $result['errors'][] = "A valid configuration was not provided. Please make sure there are at least a 'Projects' and 'Records' section to your CSV file.";
        }
        else {
            foreach ($config['projects'] as $index => $projects) {
                $startDateTime = date("Y-m-d H:i:s");
                list($records,$fields,$events,$instances,$dags,$behavior) = $this->getDefaultSettings($config,$index);
                $sourceProject = new \Project($projects[0]);
                $destProject = new \Project($projects[1]);

                $eventMapping = $this->mapEvents($sourceProject,$destProject,$events);
                $fieldMapping = $this->mapFields($sourceProject,$destProject,$fields);
                $dagMapping = $this->mapDAGs($sourceProject,$destProject,$dags);
                $instanceMapping = $this->mapInstances($instances);

                $result['data'][$index] = array('projects'=>$projects,'records'=>$records,'events'=>$eventMapping,'fields'=>$fieldMapping,'instances'=>$instanceMapping,'dags'=>$dagMapping,'behavior'=>$behavior);

                $endDateTime = date("Y-m-d H:i:s");
            }
        }
        return $result;
    }

    function validConfiguration($config) {
        return (is_array($config['projects']) && is_array($config['records']));
    }

    function getDefaultSettings($config,$index) {
        $records = $config['records'][$index];
        $events = (is_array($config['events'][$index]) ? $config['events'][$index] : array());
        $instances = (is_array($config['instances'][$index]) ? $config['instances'][$index] : array());
        $dags = (is_array($config['dags'][$index]) ? $config['dags'][$index] : array());
        $behavior = (is_array($config['behavior'][$index]) ? $config['behavior'][$index][0][0] : "delete");
        $fields = (is_array($config['fields'][$index]) ? $config['fields'][$index] : array());
        return array($records,$fields,$events,$instances,$dags,$behavior);
    }

    function mapEvents(\Project $sourceProject, \Project $destProject,$settings) {
        $returnArray = array();

        $sourceEvents = $this->getEventIds($sourceProject->project_id);
        $destEvents = $this->getEventIds($destProject->project_id);

        if (is_array($settings) && !empty($settings)) {
            foreach ($settings as $events) {
                if (in_array($events[0],$sourceEvents) && in_array($events[1],$destEvents)) {
                    $returnArray[$events[0]] = $events[1];
                }
            }
        }
        else {
            foreach ($sourceEvents as $armNum => $source) {
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

    function mapFields(\Project $sourceProject, \Project $destProject, $settings) {
        $returnArray = array();
        $sourceFields = $sourceProject->metadata;
        $destFields = $destProject->metadata;

        if (is_array($settings) && !empty($settings)) {
            foreach ($settings as $fields) {
                if (isset($sourceFields[$fields[0]]) && isset($destFields[$fields[1]]) && $this->matchingFields($sourceFields[$fields[0]],$destFields[$fields[1]])) {
                    $returnArray[$fields[0]] = $fields[1];
                }
            }
        }
        else {
            foreach ($sourceFields as $fieldName => $sMeta) {
                $dMeta = $destFields[$fieldName];
                if (is_array($sMeta) && isset($dMeta) && $this->matchingFields($sMeta,$dMeta)) {
                    $returnArray[$fieldName] = $fieldName;
                }
            }
        }
        if (!empty($returnArray) && !isset($returnArray['redcap_data_access_group'])) {
            $returnArray['redcap_data_access_group'] = 'redcap_data_access_group';
        }

        return $returnArray;
    }

    function mapDAGs(\Project $sourceProject, \Project $destProject, $settings) {
        $returnArray = array();
        $sourceDAGs = $sourceProject->getUniqueGroupNames();
        $destDAGs = $destProject->getUniqueGroupNames();

        if (is_array($settings) && !empty($settings)) {
            foreach ($settings as $dags) {
                if (is_array($sourceDAGs) && is_array($destDAGs) && in_array($dags[0],$sourceDAGs) && in_array($dags[1],$destDAGs)) {
                    $returnArray[$dags[0]] = $dags[1];
                }
            }
        }
        else {
            foreach ($sourceDAGs as $srcID => $srcName) {
                if (is_array($destDAGs) && in_array($srcName,$destDAGs)) {
                    $returnArray[$srcName] = $srcName;
                }
            }
        }

        return $returnArray;
    }

    function mapInstances($instanceList) {
        $returnArray = array();
        if (is_array($instanceList) && !empty($instanceList)) {
            foreach ($instanceList as $match) {
                $returnArray[$match[0]] = $match[1];
            }
        }
        return $returnArray;
    }

    function getEventIds($project_id) {
        $eventArray = array();
        $sql = "select a.arm_id,a.arm_num as arm_num,a.project_id,e.event_id as event_id, e.day_offset from redcap_events_metadata e, redcap_events_arms a where a.project_id = ?
				and a.arm_id = e.arm_id order by a.arm_num,e.day_offset";
        $result = $this->query($sql,[$project_id]);
        $currentEventArm = "";
        $eventCount = 0 ;
        while ($row = $result->fetch_assoc()) {
            if ($currentEventArm == "" || $currentEventArm != $row['arm_num']) {
                $currentEventArm = $row['arm_num'];
                $eventCount = 0;
            }
            $eventArray[$row['arm_num']][$eventCount] = $row['event_id'];
            $eventCount++;
        }

        return $eventArray;
    }

    function matchingFields($srcMeta,$destMeta) {
        if ($srcMeta['element_type'] == $destMeta['element_type'] && $this->matchEnum($srcMeta['element_enum'],$destMeta['element_enum'])) {
            return true;
        }
        return false;
    }

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

    function loadModuleLogs($project_id = "") {
        $returnArray = array();
        $logs = $this->queryLogs("SELECT message,moduleProjectID,sourceProjectID,destinationProjectID,behavior,startDateTime,endDateTime,user,logMessage WHERE message='Move Records module performed data migration' ".(is_numeric($project_id) ? "AND moduleProjectID = '$project_id'" : ""));

        while ($row = db_fetch_assoc($logs)) {
            $returnArray[] = $row;
        }
        return $returnArray;
    }
}