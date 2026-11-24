<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace Piwik\Plugins\EventContractAudit;

use InvalidArgumentException;
use Piwik\Common;
use Piwik\DataTable;
use Piwik\Metrics;
use Piwik\Piwik;
use Piwik\Plugins\Events\API;

/** Local aggregate-report comparison; native report reads may generate archives. */
class ComparisonService
{
    public function compare($idSite, string $period, string $date, array $plan): array
    {
        if ((!is_int($idSite) && !is_string($idSite)) || !ctype_digit((string) $idSite)
            || (int) $idSite < 1 || (string) (int) $idSite !== (string) $idSite) {
            throw new InvalidArgumentException('Select one positive site ID.');
        }
        $idSite = (int) $idSite;
        Piwik::checkUserHasViewAccess($idSite);
        $expected = $this->validatePlan($plan);
        $api = API::getInstance();
        $categories = $api->getCategory($idSite, $period, $date);
        if (!$categories instanceof DataTable) {
            throw new InvalidArgumentException('Select one report period, rather than multiple periods.');
        }
        $reasons = [];
        $observed = [];
        if ($categories->getRowFromId(DataTable::ID_SUMMARY_ROW) !== false) {
            $reasons['archive_summary_present'] = true;
        }
        foreach ($categories->getRowsWithoutSummaryRow() as $category) {
            $subtable = $category->getIdSubDataTable();
            if (!is_numeric($subtable) || (int) $subtable < 1) {
                $reasons['action_subtable_unavailable'] = true;
                continue;
            }
            // Native access, database and API errors propagate; they are not successful empty reports.
            $actions = $api->getActionFromCategoryId($idSite, $period, $date, $subtable);
            if (!$actions instanceof DataTable) {
                $reasons['action_subtable_unavailable'] = true;
                continue;
            }
            if ($actions->getRowFromId(DataTable::ID_SUMMARY_ROW) !== false) {
                $reasons['archive_summary_present'] = true;
            }
            if ($actions->getRowsWithoutSummaryRow() === []) {
                $reasons['action_subtable_unavailable'] = true;
                continue;
            }
            foreach ($actions->getRowsWithoutSummaryRow() as $action) {
                $count = $action->getColumn(Metrics::INDEX_EVENT_NB_HITS);
                if (!is_numeric($count) || !is_finite((float) $count) || (float) $count < 0
                    || floor((float) $count) !== (float) $count || (float) $count >= PHP_INT_MAX) {
                    throw new \RuntimeException('An event count is unavailable or outside the supported integer range.');
                }
                $pair = ['category' => Common::unsanitizeInputValue($category->getColumn('label')),
                    'action' => Common::unsanitizeInputValue($action->getColumn('label'))];
                $key = $this->key($pair);
                if (isset($observed[$key])) {
                    throw new \RuntimeException('The report returned duplicate logical event identities.');
                }
                $observed[$key] = $pair + ['events' => (int) $count];
            }
        }
        uasort($observed, static function (array $left, array $right): int {
            return strcmp($left['category'], $right['category']) ?: strcmp($left['action'], $right['action']);
        });
        $present = $missing = $indeterminate = [];
        foreach ($expected as $key => $pair) {
            if (isset($observed[$key])) {
                $present[] = $pair;
            } elseif ($reasons !== []) {
                $indeterminate[] = $pair;
            } else {
                $missing[] = $pair;
            }
        }
        $unexpected = [];
        foreach ($observed as $key => $row) {
            if (!isset($expected[$key])) {
                $unexpected[] = ['category' => $row['category'], 'action' => $row['action']];
            }
        }
        $codes = array_keys($reasons);
        sort($codes, SORT_STRING);
        return ['schemaVersion' => 1, 'siteId' => $idSite, 'period' => $period, 'date' => $date,
            'observedPairs' => array_values($observed), 'expectedPairsObserved' => $present,
            'expectedPairsNotObserved' => $missing, 'expectedPairsIndeterminate' => $indeterminate,
            'unexpectedPairsObserved' => $unexpected,
            'coverage' => ['status' => $codes === [] ? 'bounded' : 'incomplete', 'reasonCodes' => $codes,
                'note' => 'Visible report identities are bounded by native archive ranking. Non-observation does not diagnose broken tracking.']];
    }

    private function validatePlan(array $plan): array
    {
        $keys = array_keys($plan);
        sort($keys, SORT_STRING);
        if ($keys !== ['pairs', 'schemaVersion'] || $plan['schemaVersion'] !== 1
            || !is_array($plan['pairs']) || !array_is_list($plan['pairs'])) {
            throw new InvalidArgumentException('Plan must contain schemaVersion 1 and a pairs array.');
        }
        $result = [];
        foreach ($plan['pairs'] as $pair) {
            if (!is_array($pair)) {
                throw new InvalidArgumentException('Each pair must contain category and action strings.');
            }
            $keys = array_keys($pair);
            sort($keys, SORT_STRING);
            if ($keys !== ['action', 'category'] || !is_string($pair['category']) || $pair['category'] === ''
                || !is_string($pair['action']) || $pair['action'] === '') {
                throw new InvalidArgumentException('Each pair must contain nonempty category and action strings.');
            }
            $key = $this->key($pair);
            if (isset($result[$key])) {
                throw new InvalidArgumentException('Duplicate category/action pairs are not allowed.');
            }
            $result[$key] = ['category' => $pair['category'], 'action' => $pair['action']];
        }
        return $result;
    }

    private function key(array $pair): string
    {
        return json_encode([$pair['category'], $pair['action']], JSON_THROW_ON_ERROR);
    }
}
