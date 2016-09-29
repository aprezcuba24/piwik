<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\CoreVisualizations\Visualizations\Sparklines;
use Piwik\Common;
use Piwik\DataTable\Filter\CalculateEvolutionFilter;
use Piwik\Metrics;
use Piwik\NoAccessException;
use Piwik\Period\Range;
use Piwik\Site;
use Piwik\Url;

/**
 * DataTable Visualization that derives from Sparklines.
 */
class Config extends \Piwik\ViewDataTable\Config
{
    /**
     * Holds metrics / column names that will be used to fetch data from the configured $requestConfig API.
     * Default value: array
     */
    private $sparkline_metrics = array();

    /**
     * Holds the actual sparkline entries based on fetched data that will be used in the template.
     * @var array
     */
    private $sparklines = array();

    public function __construct()
    {
        parent::__construct();

        $this->translations = Metrics::getDefaultMetricTranslations();
    }

    /**
     * @ignore
     * @return array
     */
    public function getSparklineMetrics()
    {
        return $this->sparkline_metrics;
    }

    /**
     * @ignore
     * @return bool
     */
    public function hasSparklineMetrics()
    {
        return !empty($this->sparkline_metrics);
    }

    /**
     * Removes an existing sparkline entry. Especially useful in dataTable filters in case sparklines should be not
     * displayed depending on the fetched data.
     *
     * Example:
     * $config->addSparklineMetric('nb_users');
     * $config->filters[] = function ($dataTable) use ($config) {
     *   if ($dataTable->getFirstRow()->getColumn('nb_users') == 0) {
     *      // do not show a sparkline if there are no recorded users
     *      $config->removeSparklineMetric('nb_users');
     *   }
     * }
     *
     * @param array|string $metricNames The name of the metrics in the same format they were used when added via
     *                                  {@link addSparklineMetric}
     */
    public function removeSparklineMetric($metricNames)
    {
        foreach ($this->sparkline_metrics as $index => $metric) {
            if ($metric['columns'] === $metricNames) {
                array_splice($this->sparkline_metrics, $index, 1);

                break;
            }
        }
    }

    /**
     * Replaces an existing sparkline entry with different columns. Especially useful in dataTable filters in case
     * sparklines should be not displayed depending on the fetched data.
     *
     * Example:
     * $config->addSparklineMetric('nb_users');
     * $config->filters[] = function ($dataTable) use ($config) {
     *   if ($dataTable->getFirstRow()->getColumn('nb_users') == 0) {
     *      // instead of showing the sparklines for users, show a placeholder if there are no recorded users
     *      $config->replaceSparklineMetric(array('nb_users'), '');
     *   }
     * }
     *
     * @param array|string $metricNames The name of the metrics in the same format they were used when added via
     *                                  {@link addSparklineMetric}
     * @param array|string $replacementColumns The removed columns will be replaced with these columns
     */
    public function replaceSparklineMetric($metricNames, $replacementColumns)
    {
        foreach ($this->sparkline_metrics as $index => $metric) {
            if ($metric['columns'] === $metricNames) {
                $this->sparkline_metrics[$index]['columns'] = $replacementColumns;
            }
        }
    }

    /**
     * Adds a new sparkline.
     *
     * It will show a sparkline image, the value of the resolved metric name and a descrption. Optionally, multiple
     * values can be shown after a sparkline image by passing multiple metric names
     * (eg array('nb_visits', 'nb_actions')). The data will be requested from the configured api method see
     * {@link Piwik\ViewDataTable\RequestConfig::$apiMethodToRequestDataTable}.
     *
     * Example:
     * $config->addSparklineMetric('nb_visits');
     * $config->addTranslation('nb_visits', 'Visits');
     * Results in: [sparkline image] X visits
     *
     * Example:
     * $config->addSparklineMetric(array('nb_visits', 'nb_actions'));
     * $config->addTranslations(array('nb_visits' => 'Visits', 'nb_actions' => 'Actions'));
     * Results in: [sparkline image] X visits, Y actions
     *
     * @param string|array $metricName  Either one metric name (eg 'nb_visits') or an array of metric names
     * @param int|null $order  Defines the order. The lower the order the earlier the sparkline will be displayed.
     *                         By default the sparkline will be appended to the end.
     */
    public function addSparklineMetric($metricName, $order = null)
    {
        $this->sparkline_metrics[] = array(
            'columns' => $metricName,
            'order'   => $order
        );
    }

    /**
     * Adds a placeholder. In this case nothing will be shown, neither a sparkline nor any description. This can be
     * useful if you want to have some kind of separator. Eg if you want to have a sparkline on the left side but
     * not sparkline on the right side.
     *
     * @param int|null $order   Defines the order. The lower the order the earlier the sparkline will be displayed.
     *                          By default the sparkline will be appended to the end.
     */
    public function addPlaceholder($order = null)
    {
        $this->sparklines[] = array(
            'url' => '',
            'metrics' => array(),
            'order' => $this->getSparklineOrder($order)
        );
    }

    /**
     * Add a new sparkline to be displayed to the view.
     *
     * Each sparkline can consist of one or multiple metrics. One metric consists of a value and a description. By
     * default the value is shown first, then the description. The description can optionally contain a '%s' in case
     * the value shall be displayed within the description. If multiple metrics are given, they will be separated by
     * a comma.
     *
     * @param array $requestParamsForSparkline You need to at least set a module / action eg
     *                                         array('columns' => array('nb_visit'), 'module' => '', 'action' => '')
     * @param int|float|string|array $value Either the metric value or an array of values.
     * @param string|array $description Either one description or an array of descriptions. If an array, both
     *                                         $value and $description need the same amount of array entries.
     *                                         $description[0] should be the description for $value[0].
     *                                         $description should be already translated. If $value should appear
     *                                         somewhere within the text a `%s` can be used in the translation.
     * @param array|null $evolution            Optional array containing at least the array keys 'currentValue' and
     *                                         'pastValue' which are needed to calculate the correct percentage.
     *                                         An optional 'tooltip' can be set as well. Eg
     *                                         array('currentValue' => 10, 'pastValue' => 20,
     *                                               'tooltip' => '10 visits in 2015-07-26 compared to 20 visits in 2015-07-25')
     * @param int $order                       Defines the order. The lower the order the earlier the sparkline will be
     *                                         displayed. By default the sparkline will be appended to the end.
     * @throws \Exception In case an evolution parameter is set but has wrong data structure
     */
    public function addSparkline($requestParamsForSparkline, $value, $description, $evolution = null, $order = null)
    {
        $metrics = array();

        if (is_array($value)) {
            $values = $value;
        } else {
            $values = array($value);
        }

        if (!is_array($description)) {
            $description = array($description);
        }

        if (!empty($requestParamsForSparkline['columns'])
            && count($requestParamsForSparkline['columns']) === count($values)) {
            $columns = array_values($requestParamsForSparkline['columns']);
        } else {
            $columns = array();
        }

        if (count($values) === count($description)) {
            foreach ($values as $index => $value) {
                $metrics[] = array(
                    'column' => isset($columns[$index]) ? $columns[$index] : '',
                    'value' => $value,
                    'description' => $description[$index]
                );
            }
        } else {
            $msg  = 'The number of values and descriptions need to be the same to add a sparkline. ';
            $msg .= 'Values: ' . implode(', ', $values). ' Descriptions: ' . implode(', ', $description);
            throw new \Exception($msg);
        }

        if (empty($metrics)) {
            return;
        }

        $sparkline = array(
            'url' => $this->getUrlSparkline($requestParamsForSparkline),
            'metrics' => $metrics,
            'order' => $this->getSparklineOrder($order)
        );

        if (!empty($evolution)) {
            if (!is_array($evolution) ||
                !array_key_exists('currentValue', $evolution) ||
                !array_key_exists('pastValue', $evolution)) {
                throw new \Exception('In order to show an evolution in the sparklines view a currentValue and pastValue array key needs to be present');
            }

            $evolutionPercent = CalculateEvolutionFilter::calculate($evolution['currentValue'], $evolution['pastValue'], $precision = 1);

            // do not display evolution if evolution percent is 0 and current value is 0
            if ($evolutionPercent != 0 || $evolution['currentValue'] != 0) {
                $sparkline['evolution'] = array(
                    'percent' => $evolutionPercent,
                    'tooltip' => !empty($evolution['tooltip']) ? $evolution['tooltip'] : null
                );
            }

        }

        $this->sparklines[] = $sparkline;
    }

    /**
     * @return array
     * @ignore
     */
    public function getSortedSparklines()
    {
        usort($this->sparklines, function ($a, $b) {
            if ($a['order'] == $b['order']) {
                return 0;
            }
            return ($a['order'] < $b['order']) ? -1 : 1;
        });

        return $this->sparklines;
    }

    private function getSparklineOrder($order)
    {
        if (!isset($order)) {
            // make sure to append to the end if nothing set (in the order they are added)
            $order = 999 + count($this->sparklines);
        }

        return (int) $order;
    }

    /**
     * Returns a URL to a sparkline image for a report served by the current plugin.
     *
     * The result of this URL should be used with the [sparkline()](/api-reference/Piwik/View#twig) twig function.
     *
     * The current site ID and period will be used.
     *
     * @param array $customParameters The array of query parameter name/value pairs that
     *                                should be set in result URL.
     * @return string The generated URL.
     */
    private function getUrlSparkline($customParameters = array())
    {
        $customParameters['viewDataTable'] = 'sparkline';

        $params = $this->getGraphParamsModified($customParameters);

        // convert array values to comma separated
        foreach ($params as &$value) {
            if (is_array($value)) {
                $value = rawurlencode(implode(',', $value));
            }
        }
        $url = Url::getCurrentQueryStringWithParametersModified($params);
        return $url;
    }

    /**
     * Returns the array of new processed parameters once the parameters are applied.
     * For example: if you set range=last30 and date=2008-03-10,
     *  the date element of the returned array will be "2008-02-10,2008-03-10"
     *
     * Parameters you can set:
     * - range: last30, previous10, etc.
     * - date: YYYY-MM-DD, today, yesterday
     * - period: day, week, month, year
     *
     * @param array $paramsToSet array( 'date' => 'last50', 'viewDataTable' =>'sparkline' )
     * @throws \Piwik\NoAccessException
     * @return array
     */
    private function getGraphParamsModified($paramsToSet = array())
    {
        if (!isset($paramsToSet['period'])) {
            $period = Common::getRequestVar('period');
        } else {
            $period = $paramsToSet['period'];
        }

        if ($period == 'range') {
            return $paramsToSet;
        }

        if (!isset($paramsToSet['range'])) {
            $range = 'last30';
        } else {
            $range = $paramsToSet['range'];
        }

        if (!isset($paramsToSet['idSite'])) {
            $idSite = Common::getRequestVar('idSite');
        } else {
            $idSite = $paramsToSet['idSite'];
        }

        if (!isset($paramsToSet['date'])) {
            $endDate = Common::getRequestVar('date', 'yesterday', 'string');
        } else {
            $endDate = $paramsToSet['date'];
        }

        $site = new Site($idSite);

        if (is_null($site)) {
            throw new NoAccessException("Website not initialized, check that you are logged in and/or using the correct token_auth.");
        }

        $paramDate = Range::getRelativeToEndDate($period, $range, $endDate, $site);

        $params = array_merge($paramsToSet, array('date' => $paramDate));
        return $params;
    }

}
