<?php
error_reporting( E_ALL & ~E_STRICT);
set_time_limit(0);
// if (PHP_SAPI != 'cli') {
//     echo "This is a console script.\n";
//     exit(1);
// }
define('sugarEntry', true);
require_once 'include/entryPoint.php';
require_once 'custom/include/SugarLogger/IBMLoggerManager/IBMLoggerManager.php';
$logger = new IBMLoggerManager();
$logger->setLogPath('defect_115852', "./ibm/upgrade/ibm_r39_sev1_40/scripts/php/manual/");
$logger->setLevel('info');
$limitItems = 500;
$pageSize = 100;
global $db;
// Restore those roadmaps that had been deleted by autoclaim DI job
if ($argc == 1) {
    // Create roadmap for those RLI that don't have lio_own roadmap
    $result = $db->query("select count(1) as cnt from IBM_REVENUELINEITEMS as RLI
        inner join OPPORTUNEVENUELINEITEMS_C relat on relat.OPPORTU503DTEMS_IDB=RLI.id and relat.deleted=0
        inner join opportunities oppty on oppty.id = relat.OPPORTUF4B1TIES_IDA and oppty.deleted=0
          and oppty.bp_private_opportunity = '0'
        left join IBM_ROADMAPS as R on (R.RLI_ID = RLI.ID and R.DELETED=0 and R.LIO_OWN=1)
        where RLI.DELETED=0
        and R.ID is null
        and RLI.REVENUE_TYPE <> 'NONE'
    ");
    $row = $db->fetchByAssoc($result);
    $totalItems = $row['cnt'];
    $db->commit();
    outlog("\n*** Total {$totalItems} RLIs are lacking of lio own roadmap. ***");
    outlog("\n*** START: Create roadmap  for those RLIs that don't have lio_own roadmap. ***");
    if ($totalItems == 0) {
        outlog("\n*** No roadmaps need to be fixed. ***");
        exit(1);
    }
    if ($totalItems < $limitItems) {
        echo shell_exec('php -d display_errors ' . $argv[0] . ' ' . $totalItems . ' ' . $totalItems);
    } else {
        $start = 0;
        do {
            $start += $limitItems;
            // echo shell_exec('php -d display_errors ' . $argv[0] . ' ' . $totalItems . ' ' . $limitItems);
            system("php -d display_errors {$argv[0]} {$totalItems} {$limitItems} {$start}  2>&1");
        } while ($start < $totalItems);
    }
    outlog("*** END: total {$totalItems} roadmap have been fixed. ***");
} else {
    outlog("\n--");
    $totalItems = intval($argv[1]);
    $items = intval($argv[2]);
    $alreadyDone = isset($argv[3]) ? intval($argv[3]) - $limitItems : 0;
    $rliIds = array();
    $result = $db->query("select
         rlis.id, rlis.assigned_user_id, rlis.fcast_date_tran,
         rlis.fcast_date_sign, roadmaps.id as r_id, roadmaps.assigned_user_id as r_user
      from ibm_revenuelineitems rlis
      inner join OPPORTUNEVENUELINEITEMS_C relat on relat.OPPORTU503DTEMS_IDB=rlis.id and relat.deleted=0
      inner join opportunities oppty on oppty.id = relat.OPPORTUF4B1TIES_IDA and oppty.deleted=0
        and oppty.bp_private_opportunity = '0'
      left join  ibm_roadmaps roadmaps on roadmaps.lio_own=1 and roadmaps.deleted=0 and rlis.id=roadmaps.rli_id
    where
      rlis.revenue_type <> 'NONE'
      and roadmaps.ID is null
      and rlis.deleted=0 limit {$items}
    ");
    $GLOBALS['locale'] = $locale = Localization::getObject();
    $GLOBALS['current_user'] = BeanFactory::getBean("Users", 1);
    $start = 0;
    while ($row = $db->fetchByAssoc($result)) {
        if (in_array($row['id'], $rliIds)) {
            continue;
        }
        // remove the bade roadmap due the the incorrect assigned user.
        if (!empty($row['r_user'])) {
            $rmBean = BeanFactory::getBean("ibm_Roadmaps", $row['r_id']);
            $rmBean->mark_deleted($row['r_id']);
        }
        array_push($rliIds, $row['id']);
        $rli = BeanFactory::getBean("ibm_RevenueLineItems", $row['id']);
        // $rliBean->save();
        createRoadmapsFromRLI($rli);
        $rli = null;
        $start++;
        if ($start % $pageSize == 0) {
            $db->commit();
            outlog("*** " . ($alreadyDone + $start) . " of {$totalItems} roadmap have been fixed. ***");
        }
    }
}
$db->commit();
exit(0);
/**
 * @param RLIBean $focus
 */
function createRoadmapsFromRLI(&$focus)
{
    $roadmapsHelper = IBMHelper::getClass('Roadmaps');
    $roadmapsBean = updateRoadmapsBean($focus, $roadmapsHelper->getRoadmapsBean());
    if ($roadmapsBean && isset($roadmapsBean->assigned_user_id) && !empty($roadmapsBean->assigned_user_id)) {
        $roadmapsBean->last_updating_system = 'defect_115852';
        $roadmapsBean->last_updating_system_date = $GLOBALS['timedate']->nowDb();
        $roadmapsBean->save();
                outlog("Create roadmap with id: $roadmapsBean->id");
    }
}

/**
 * @param String $message
 * @return bool
 */
function outlog($message)
{
    global $logger;
    echo $message . "\n";
    if ($logger) {
        $logger->info($message);
    }
    return true;
}
/**
 * Update roadmap bean
 *
 * @param SugarBean $rliBean
 * @param SugarBean $roadmapsBean
 * @param array $updateFields
 * @param string $type
 * @return Ambigous <NULL, SugarBean>
 */
function updateRoadmapsBean(
    SugarBean $rliBean,
    SugarBean $roadmapsBean,
    $updateFields = array(),
    $type = 'insert'
) {
    if ($type == 'insert') {
        $updateFields = array(
                'revenue_type' => 'revenue_type',
                'probability' => 'probability',
                'roadmap_status' => 'roadmap_status',
                'revenue_amount' => 'revenue_amount',
        );
        $updateFields['forecast_date'] = $rliBean->revenue_type == 'Transactional'
            ? 'fcast_date_tran' : 'fcast_date_sign';
        $roadmapsBean->lio_own = 1;
        $roadmapsBean->rli_id = $rliBean->id;
        // use RLI bp id if there is no RLI user id
        if (isset($rliBean->assigned_user_id) && !empty($rliBean->assigned_user_id)) {
            $updateFields['assigned_user_id'] = 'assigned_user_id';
        } else {
            $updateFields['assigned_user_id'] = 'assigned_bp_id';
        }
        // when revenue_type is sigings-acv or saas-ext, use swg_annual_value
        $rt = strtolower($rliBean->revenue_type);
        if ($rt == 'signings-acv' || $rt == 'saas-ext') {
            $updateFields['revenue_amount'] = 'swg_annual_value';
        }
        
        // change 52647
        // when create a new roadmap and rli green_blue_revenue is blue
        // do not calculate cadence
        if (!empty($rliBean->green_blue_revenue)
        && 'blue' == strtolower($rliBean->green_blue_revenue)) {
            $roadmapsBean->blueCaculate = 'ignore';
        } else {
            $roadmapsBean->blueCaculate = 'normal';
        }
    }
    foreach ($updateFields as $key => $val) {
        /*
         * When save a forecast, it will call rli->save() function, so this hook will be triggereed,
         * before this, the forecast has been updated, so there need a check, when the forecast fields
         * which are needed to be update are equal with RLI's data, this forecast should not be saved again.
         */
        if (!isset($rliBean->$val)
            || ($key == 'forecast_date' && (strtotime($roadmapsBean->$key) == strtotime($rliBean->$val)))
            || ($key != 'revenue_amount'
            && isset($roadmapsBean->$key )
            && ($roadmapsBean->$key == $rliBean->$val))
        ) {
            unset($updateFields[$key]);
            continue;
        }
        if ('revenue_amount' == $key) {
            // Task 52669: when level10 is software(B7000) and revenut_type is
            // sigings, use swg_annual_value
            if ($val == 'swg_annual_value' && $rliBean->duration > 12) {
                $roadmapsBean->$key = $rliBean->revenue_amount / $rliBean->duration * 12;
            } else {
                $roadmapsBean->$key = $rliBean->revenue_amount;
            }
            if (!isset($rliBean->currency_id) || empty($rliBean->currency_id) || $rliBean->currency_id != '-99') {
                $roadmapsBean->$key = IBMHelper::getClass('Currencies')
                ->convertCurrency($roadmapsBean->$key, $rliBean->currency_id, '-99');
            }
            // defect 71180: makesure the value is string, and skip the format funciton in the bean save
            $roadmapsBean->$key = strval($roadmapsBean->$key);
            continue;
        }
        $roadmapsBean->$key = $rliBean->$val;
    }
    // update roadmaps tiemperiod_id when the forecast_date is changed.
    if (isset($updateFields['forecast_date'])) {
        $roadmapsBean->timeperiod_id = IBMHelper::getClass('Roadmaps')
            ->getTimeperiodId($roadmapsBean->forecast_date);
    }
    if (empty($roadmapsBean->opportunity_id )) {
        $roadmapsBean->opportunity_id = isset($rliBean->opportunityId)
                ? $rliBean->opportunityId : $rliBean->getOpportunity()->id;
    }
    //add for defect 55265
    //prevents infinite loops
    $roadmapsBean->saveFromRLI = true;
    if (!empty($rliBean->assigned_bp_id && empty($rliBean->assigned_user_id))) {
        $roadmapsBean->forecasting_role = 'BPEMP';
        $roadmapsBean->brand_version = 'GBP';
    }
    // return null;
    return count($updateFields) > 0 ? $roadmapsBean : null;
}
