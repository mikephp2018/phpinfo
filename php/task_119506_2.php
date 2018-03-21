<?php
set_time_limit(0);

define('sugarEntry', true);
require_once('include/entryPoint.php');
require_once('custom/include/SugarLogger/IBMLoggerManager/IBMLoggerManager.php');
$logger = new IBMLoggerManager();
$logger->setLogPath('task_119506_2', "ibm/upgrade/ibm_r42/scripts/php/manual/");
$logger->setLevel('fatal');

$limitItems = 100;
global $db;

if ($argc == 1) {
    outlog("\n*** SCENARIO 2: ***", true);
    $sql_WpCount = "SELECT
                COUNT(*) AS c
            FROM
                ibm_roadmaps rm INNER JOIN ibm_roadmaps rmbp
                    ON rm.RLI_ID = rmbp.RLI_ID
                AND rm.deleted = 0
                AND rmbp.deleted = 0
                AND rm.lio_own = 1
                AND rm.is_seller = 1
                AND rmbp.lio_own = 0
                AND rmbp.FORECASTING_ROLE = 'BPEMP'
                AND rmbp.BRAND_VERSION = 'GBP'
            WHERE
                rm.related_to_roadmap_id <> rmbp.id
                OR rm.related_to_roadmap_id IN NULL";

    $row = $db->fetchOne($sql_WpCount);
    $totalRlis = $row['c'];
    if (!empty($row) && !empty($totalRlis)) {
        outlog("\n*** Total of {$totalRlis} records will impacted ***", true);
    } else {
        outlog("\n*** No DATA in SCENARIO. ***", true);
        outlog("\n*** END: RESTORE ROADMAPS. ***", true);
        exit(0);
    }
    if ($totalRlis < $limitItems) {
        echo shell_exec('php -d display_errors ' . $argv[0] . ' ' . $totalRlis . ' ' . $totalRlis);
    } else {
        $start = 0;
        do {
            $start += $limitItems;
            system("php -d display_errors {$argv[0]} {$totalRlis} {$limitItems} {$start}  2>&1");
        } while ($start < $totalRlis);
    }
    outlog("\n*** END: RESTORE ROADMAPS. ***", true);
    exit(0);
} else {
    $totalRlis = intval($argv[1]);
    $items = intval($argv[2]);
    if ($argc == 4) {
        $alreadyDone = intval($argv[3]) - $limitItems;
    } else {
        $alreadyDone = 0;
    }
    $select_sql = "SELECT
                   rm.id AS id, rmbp.id AS rmoid
                FROM
                    ibm_roadmaps rm INNER JOIN ibm_roadmaps rmbp
                        ON rm.RLI_ID = rmbp.RLI_ID
                    AND rm.deleted = 0
                    AND rmbp.deleted = 0
                    AND rm.lio_own = 1
                    AND rm.is_seller = 1
                    AND rmbp.lio_own = 0
                    AND rmbp.FORECASTING_ROLE = 'BPEMP'
                    AND rmbp.BRAND_VERSION = 'GBP'
                WHERE
                    rm.related_to_roadmap_id <> rmbp.id
                    OR rm.related_to_roadmap_id IN NULL
                LIMIT 0, {$items}";
    $result = $db->query($select_sql);
    while ($row = $db->fetchByAssoc($result)) {
        updateByBeanSave($row);
    }
    $total = 100;
    outlog(
        "\n*** " . (($alreadyDone + $total) > $totalRlis ? $totalRlis : ($alreadyDone + $total)) .
        "/{$totalRlis} records has been done. ***",
        true
    );
    exit(0);
}
/**
 * @param Array $row
 */
function updateByBeanSave(&$row)
{
    global $db;
    $rmBean = IBMHelper::getClass('Utilities')->getLightBean('ibm_Roadmaps', array('id' => $row['id']));
    $rmBean->related_to_roadmap_id = $row['rmoid'];
    $rmBean->saveFromClean = true;
    $rmBean->saveFromRLI = true;
    $rmBean->save();
    $db->commit();
}
/**
 * @param String $message
 * @param bool $output
 * @return bool
 */
function outlog($message, $output = true)
{
    global $logger;
    if ($output) {
        echo $message . "\n";
    }
    if ($logger) {
        $logger->fatal($message);
    }
    return true;
}
