<?php

// https://developers.google.com/analytics/devguides/reporting/core/v4/quickstart/service-php
// https://developers.google.com/analytics/devguides/reporting/core/v4/samples#cohorts

// Load the Google API PHP Client Library.
require_once __DIR__ . '/vendor/autoload.php';

$viewId = '227751717';

$credentials = __DIR__ . '/credentials.json';

$analytics = initializeAnalytics($credentials);

$response = cohortRequest($analytics,$viewId,0);
$responsePreviousPeriod = cohortRequest($analytics,$viewId,4);

$formatted = formatResults($response);
$formattedPreviousPeriod = formatResults($responsePreviousPeriod);

echo json_encode(formatGeckoboard($formatted,$formattedPreviousPeriod));die();


/**
 * Initializes an Analytics Reporting API V4 service object.
 *
 * @return An authorized Analytics Reporting API V4 service object.
 */
function initializeAnalytics($credentials)
{
  // Create and configure a new client object.
  $client = new Google_Client();
  $client->setApplicationName("Hello Analytics Reporting");
  $client->setAuthConfig($credentials);
  $client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);
  $analytics = new Google_Service_AnalyticsReporting($client);

  return $analytics;
}

function cohortRequest(&$analyticsreporting,$viewId,$weeksBack) {
    // Create the ReportRequest object.
    $request = new Google_Service_AnalyticsReporting_ReportRequest();
    $request->setViewId($viewId);

    $cohortDimension = new Google_Service_AnalyticsReporting_Dimension();
    $cohortDimension->setName("ga:cohort");

    $cohortNthWeekDimension = new Google_Service_AnalyticsReporting_Dimension();
    $cohortNthWeekDimension->setName("ga:cohortNthWeek");

    // Set the cohort dimensions
    $request->setDimensions(array($cohortDimension, $cohortNthWeekDimension));

    $cohortRetentionRate = new Google_Service_AnalyticsReporting_Metric();
    $cohortRetentionRate->setExpression("ga:cohortRetentionRate");

    // Set the cohort metrics
    $request->setMetrics(array($cohortRetentionRate));

    // Create cohorts
    $cohorts = [];
    $i = 1;
    while($i <= 3) {
      $weeksBack = $weeksBack + $i;
      $dateStart = date('Y-m-d', strtotime("-${weeksBack} week last sunday"));
      $dateEnd = date('Y-m-d', strtotime("-${weeksBack} week next saturday"));
      $dateRange = new Google_Service_AnalyticsReporting_DateRange();
      $dateRange->setStartDate($dateStart);
      $dateRange->setEndDate($dateEnd);
      $cohort = new Google_Service_AnalyticsReporting_Cohort();
      $cohort->setName($dateStart . '-' . $dateEnd);
      $cohort->setType("FIRST_VISIT_DATE");
      $cohort->setDateRange($dateRange);
      $cohorts[] = $cohort;
      $i++;
    }

    // Create the cohort group
    $cohortGroup = new Google_Service_AnalyticsReporting_CohortGroup();
    $cohortGroup->setCohorts($cohorts);
    //$cohortGroup->setLifetimeValue(true);

    $request->setCohortGroup($cohortGroup);

    // Create the GetReportsRequest object.
    $getReport = new Google_Service_AnalyticsReporting_GetReportsRequest();
    $getReport->setReportRequests(array($request));

    // Call the batchGet method.
    $body = new Google_Service_AnalyticsReporting_GetReportsRequest();
    $body->setReportRequests( array($request) );
    $response = $analyticsreporting->reports->batchGet( $body );

    return $response->getReports();
}

/**
 * Format the Analytics Reporting API V4 response.
 *
 * @param An Analytics Reporting API V4 response.
 */
function formatResults($reports) {
  for ( $reportIndex = 0; $reportIndex < count( $reports ); $reportIndex++ ) {
    $report = $reports[ $reportIndex ];
    $rows = $report->getData()->getRows();

    for ( $rowIndex = 0; $rowIndex < count($rows); $rowIndex++) {
      $row = $rows[ $rowIndex ];
      $dimensions = $row->getDimensions();
      $metrics = $row->getMetrics()[0]['values'][0];

      $cohorts[$dimensions[0]][intval($dimensions[1])] = $metrics;

      // averages
      $averages[intval($dimensions[1])][] = $metrics;
    }
  }

  // Calculate averages
  $averages = array_map(function($elements){
    $sum = 0;
    foreach($elements as $nb) {
      $sum += floatval($nb);
    }
    return $sum/count($elements);
  },$averages);

  return [
    'cohorts' => $cohorts,
    'averages' => $averages
  ];
}

/**
 * Format for geckoboard a formatted Analytics Reporting API V4 response.
 *
 * @param An Analytics Reporting API V4 response.
 */
function formatGeckoboard($formattedResults,$formattedResultsPreviousPeriod) {
  $xaxis = [];
  $values = [];
  $valuesPreviousPeriod = [];
  $i = 0;
  while($i < count($formattedResults['averages'])) {
    $xaxis[] = 'Week ' . ($i+1);
    $values[] = $formattedResults['averages'][$i];
    $valuesPreviousPeriod[] = $formattedResultsPreviousPeriod['averages'][$i];
    $i++;
  }
  return [
    'x_axis' => [
      'labels' => $xaxis,
      'type' => 'standard'
    ],
    'series' => [
      [
      	'name' => 'This month',
        'data' => $values
      ],
	  [
      	'name' => 'Last month',
        'data' => $valuesPreviousPeriod
      ]
    ]
  ];
}
