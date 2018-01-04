<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This file contains a class definition for the LISResults container resource
 *
 * @package    ltiservice_gradebookservices
 * @copyright  2017 Cengage Learning http://www.cengage.com
 * @author     Dirk Singels, Diego del Blanco, Claude Vervoort
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace ltiservice_gradebookservices\local\resource;

use ltiservice_gradebookservices\local\service\gradebookservices;

defined('MOODLE_INTERNAL') || die();

/**
 * A resource implementing LISResults container.
 *
 * @package    ltiservice_gradebookservices
 * @since      Moodle 3.0
 * @copyright  2017 Cengage Learning http://www.cengage.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class results extends \mod_lti\local\ltiservice\resource_base {

    /**
     * Class constructor.
     *
     * @param ltiservice_gradebookservices\local\service\gradebookservices $service Service instance
     */
    public function __construct($service) {

        parent::__construct($service);
        $this->id = 'Result.collection';
        $this->template = '/{context_id}/lineitems/{item_id}/results';
        $this->variables[] = 'Results.url';
        $this->formats[] = 'application/vnd.ims.lis.v1.resultcontainer+json';
        $this->methods[] = 'GET';
    }

    /**
     * Execute the request for this resource.
     *
     * @param mod_lti\local\ltiservice\response $response  Response object for this request.
     */
    public function execute($response) {
        global $CFG, $DB;

        $params = $this->parse_template();
        $contextid = $params['context_id'];
        $itemid = $params['item_id'];

        $isget = $response->get_request_method() === 'GET';
        if ($isget) {
            $contenttype = $response->get_accept();
        } else {
            $contenttype = $response->get_content_type();
        }
        $container = empty($contenttype) || ($contenttype === $this->formats[0]);
        // We will receive typeid when working with LTI 1.x, if not the we are in LTI 2.
        if (isset($_GET['typeid'])) {
            $typeid = $_GET['typeid'];
        } else {
            $typeid = null;
        }
        try {
            if (is_null($typeid)) {
                if (!$this->check_tool_proxy(null, $response->get_request_data())) {
                    throw new \Exception(null, 401);
                }
            } else {
                if (!$this->check_type($typeid, $contextid,  'Result.collection:get', $response->get_request_data())) {
                    throw new \Exception(null, 401);
                }
            }
            if (empty($contextid) || (!empty($contenttype) && !in_array($contenttype, $this->formats))) {
                throw new \Exception(null, 400);
            }
            if ($DB->get_record('course', array('id' => $contextid)) === false) {
                throw new \Exception(null, 404);
            }
            if ($DB->get_record('grade_items', array('id' => $itemid)) === false) {
                throw new \Exception(null, 404);
            }
            if (($item = $this->get_service()->get_lineitem($contextid, $itemid, $typeid)) === false) {
                throw new \Exception(null, 403);
            }
            if (is_null($typeid)) {
                if (isset($item->iteminstance) && (!gradebookservices::check_lti_id($item->iteminstance, $item->courseid,
                        $this->get_service()->get_tool_proxy()->id))) {
                            throw new \Exception(null, 403);
                }
            } else {
                if (isset($item->iteminstance) && (!gradebookservices::check_lti_1x_id($item->iteminstance, $item->courseid,
                        $typeid))) {
                            throw new \Exception(null, 403);
                }
            }
            require_once($CFG->libdir.'/gradelib.php');
            switch ($response->get_request_method()) {
                case 'GET':
                    if (isset($_GET['limit'])) {
                        gradebookservices::validate_paging_query_parameters($_GET['limit']);
                    }
                    $limitnum = optional_param('limit', 0, PARAM_INT);
                    if (isset($_GET['from'])) {
                        gradebookservices::validate_paging_query_parameters($limitnum, $_GET['from']);
                    }
                    $limitfrom = optional_param('from', 0, PARAM_INT);
                    $json = $this->get_request_json($item->id, $limitfrom, $limitnum, $typeid, $response);
                    $response->set_content_type($this->formats[0]);
                    $response->set_body($json);
                    break;
                default:  // Should not be possible.
                    throw new \Exception(null, 405);
            }
            $response->set_body($json);

        } catch (\Exception $e) {
            $response->set_code($e->getCode());
        }

    }

    /**
     * Generate the JSON for a GET request.
     *
     * @param int    $itemid     Grade item instance ID
     * @param string $limitfrom  Offset for the first result to include in this paged set
     * @param string $limitnum   Maximum number of results to include in the response, ignored if zero
     * @param int    $typeid     Lti tool typeid (or null)
     * @param string $response   The response element needed to add a header.
     *
     * return string
     */
    private function get_request_json($itemid, $limitfrom, $limitnum, $typeid, $response) {


        $grades = \grade_grade::fetch_all(array('itemid' => $itemid));

        if ($grades && isset($limitnum) && $limitnum > 0) {
            // Since we only display grades that have been modified, we need to filter first in order to support
            // paging.
            $resultgrades = array_filter($grades, function ($grade) {
                return !empty($grade->timemodified);
            });
            // We save the total count to calculate the last page.
            $totalcount = count($resultgrades);
            // We slice to the requested item offset to insure proper item is always first, and we always return
            // first pageset of any remaining items.
            $grades = array_slice($resultgrades, $limitfrom);
            if (count($grades) > 0) {
                $pagedgrades = array_chunk($grades, $limitnum);
                $pageset = 0;
                $grades = $pagedgrades[$pageset];
            }
            if ($limitfrom >= $totalcount || $limitfrom < 0) {
                $outofrange = true;
            } else {
                $outofrange = false;
            }
            $limitprev = $limitfrom - $limitnum >=0 ? $limitfrom - $limitnum : 0;
            $limitcurrent= $limitfrom;
            $limitlast = $totalcount-$limitnum+1 >= 0 ? $totalcount-$limitnum+1: 0;
            $limitfrom += $limitnum;
            if (is_null($typeid)) {
                if (($limitfrom <= $totalcount -1) && (!$outofrange)) {
                    $nextpage = $this->get_endpoint() . "?limit=" . $limitnum . "&from=" . $limitfrom;
                } else {
                    $nextpage= null;
                }
                $firstpage = $this->get_endpoint() . "?limit=" . $limitnum . "&from=0";
                $canonicalpage = $this->get_endpoint() . "?limit=" . $limitnum . "&from=" . $limitcurrent;
                $lastpage = $this->get_endpoint() . "?limit=" . $limitnum . "&from=" . $limitlast;
                if (($limitcurrent > 0) && (!$outofrange)) {
                    $prevpage = $this->get_endpoint() . "?limit=" . $limitnum . "&from=" . $limitprev;
                } else {
                    $prevpage = null;
                }
            } else {
                if (($limitfrom <= $totalcount -1) && (!$outofrange)) {
                    $nextpage = $this->get_endpoint() . "?typeid=" . $typeid . "&limit=" . $limitnum . "&from=" . $limitfrom;
                } else {
                    $nextpage= null;
                }
                $firstpage = $this->get_endpoint() . "?typeid=" . $typeid . "&limit=" . $limitnum . "&from=0";
                $canonicalpage = $this->get_endpoint() . "?typeid=" . $typeid . "&limit=" . $limitnum . "&from=" . $limitcurrent;
                if (($limitcurrent > 0) && (!$outofrange)) {
                    $prevpage = $this->get_endpoint() . "?typeid=" . $typeid . "&limit=" . $limitnum . "&from=" . $limitprev;
                } else {
                    $prevpage = null;
                }
            }
        }

        $json = <<< EOD
{
  "results" : [
EOD;
        $lineitem = new lineitem($this->get_service());
        $endpoint = $lineitem->get_endpoint();
        $sep = "\n        ";
        if ($grades) {
            foreach ($grades as $grade) {
                if (!empty($grade->timemodified)) {
                    $json .= $sep . gradebookservices::result_to_json($grade, $endpoint, $typeid);
                    $sep = ",\n        ";
                }
            }
        }
        $json .= <<< EOD

  ]
}
EOD;
        if (isset($canonicalpage) && ($canonicalpage)) {
            $links = 'links: <' . $firstpage . '>; rel=“first”';
            if (!(is_null($prevpage))) {
                $links .= ', <' . $prevpage . '>; rel=“prev”';
            }
            $links .= ', <' . $canonicalpage. '>; rel=“canonical”';
            if (!(is_null($nextpage))) {
                $links .= ', <' . $nextpage . '>; rel=“next”';
            }
            $links .= ', <' . $lastpage . '>; rel=“last”';
            // Disabled until add_additional_header is included in the core code.
            // $response->add_additional_header($links);
        }
        return $json;
    }

    /**
     * get permissions from the config of the tool for that resource
     *
     * @return Array with the permissions related to this resource by the $lti_type or null if none.
     */
    public function get_permissions($typeid) {
        $tool = lti_get_type_type_config($typeid);
        if ($tool->ltiservice_gradesynchronization == '1') {
            return array('Result.collection:get');
        } else if ($tool->ltiservice_gradesynchronization == '2') {
            return array('Result.collection:get');
        } else {
            return array();
        }
    }

    /**
     * Parse a value for custom parameter substitution variables.
     *
     * @param string $value String to be parsed
     *
     * @return string
     */
    public function parse_value($value) {
        global $COURSE, $CFG;

        if (strpos($value, '$Results.url') !== false) {
            require_once($CFG->libdir . '/gradelib.php');

            $resolved = '';
            $this->params['context_id'] = $COURSE->id;
            $id = optional_param('id', 0, PARAM_INT); // Course Module ID.
            if (!empty($id)) {
                $cm = get_coursemodule_from_id('lti', $id, 0, false, MUST_EXIST);
                $id = $cm->instance;
                $item = grade_get_grades($COURSE->id, 'mod', 'lti', $id);
                if ($item && $item->items) {
                    $this->params['item_id'] = $item->items[0]->id;
                    $resolved = parent::get_endpoint();
                }
            }
            $value = str_replace('$Results.url', $resolved, $value);
        }

        return $value;
    }
}
