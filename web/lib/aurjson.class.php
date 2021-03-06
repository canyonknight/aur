<?php
/**
 * AurJSON
 *
 * This file contains the AurRPC remote handling class
 **/
include_once("aur.inc.php");

/**
 * This class defines a remote interface for fetching data
 * from the AUR using JSON formatted elements.
 * @package rpc
 * @subpackage classes
 **/
class AurJSON {
    private $dbh = false;
    private static $exposed_methods = array(
        'search', 'info', 'multiinfo', 'msearch', 'suggest'
    );
    private static $fields = array(
        'Packages.ID', 'Name', 'Version', 'CategoryID', 'Description', 'URL',
        'License', 'NumVotes', 'OutOfDateTS AS OutOfDate',
        'SubmittedTS AS FirstSubmitted', 'ModifiedTS AS LastModified'
    );
    private static $numeric_fields = array(
        'ID', 'CategoryID', 'NumVotes', 'OutOfDate', 'FirstSubmitted',
        'LastModified'
    );

    /**
     * Handles post data, and routes the request.
     * @param string $post_data The post data to parse and handle.
     * @return string The JSON formatted response data.
     **/
    public function handle($http_data) {
		// unset global aur headers from aur.inc
		// leave expires header to enforce validation
		// header_remove('Expires');
		// unset global aur.inc pragma header. We want to allow caching of data
		// in proxies, but require validation of data (if-none-match) if
		// possible
		header_remove('Pragma');
		// overwrite cache-control header set in aur.inc to allow caching, but
		// require validation
		header('Cache-Control: public, must-revalidate, max-age=0');
		header('Content-Type: application/json, charset=utf-8');

        // handle error states
        if ( !isset($http_data['type']) || !isset($http_data['arg']) ) {
            return $this->json_error('No request type/data specified.');
        }

        // do the routing
        if ( in_array($http_data['type'], self::$exposed_methods) ) {
            // set up db connection.
            $this->dbh = DB::connect();

            // ugh. this works. I hate you php.
            $json = call_user_func(array(&$this, $http_data['type']),
                $http_data['arg']);

			// calculate etag as an md5 based on the json result
			// this could be optimized by calculating the etag on the 
			// query result object before converting to json (step into
			// the above function call) and adding the 'type' to the response,
			// but having all this code here is cleaner and 'good enough'
			$etag = md5($json);
			header("Etag: \"$etag\"");
			// make sure to strip a few things off the if-none-match
			// header. stripping whitespace may not be required, but 
			// removing the quote on the incoming header is required 
			// to make the equality test
			$if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ?
				trim($_SERVER['HTTP_IF_NONE_MATCH'], "\t\n\r\" ") : false;
			if ($if_none_match && $if_none_match == $etag) {
				header('HTTP/1.1 304 Not Modified');
				return;
			}

            // allow rpc callback for XDomainAjax
            if ( isset($http_data['callback']) ) {
                // it is more correct to send text/javascript
                // content-type for jsonp-callback
                header('content-type: text/javascript');
                return $http_data['callback'] . "({$json})";
            }
            else {
                // set content type header to app/json
                header('content-type: application/json');
                return $json;
            }
        }
        else {
            return $this->json_error('Incorrect request type specified.');
        }
    }

    /**
     * Returns a JSON formatted error string.
     *
     * @param $msg The error string to return
     * @return mixed A json formatted error response.
     **/
    private function json_error($msg) {
        // set content type header to app/json
        header('content-type: application/json');
        return $this->json_results('error', 0, $msg);
    }

    /**
     * Returns a JSON formatted result data.
     * @param $type The response method type.
     * @param $data The result data to return
     * @return mixed A json formatted result response.
     **/
    private function json_results($type, $count, $data) {
        return json_encode( array('type' => $type, 'resultcount' => $count, 'results' => $data) );
    }

    private function process_query($type, $where_condition) {
        global $MAX_RPC_RESULTS;
        $fields = implode(',', self::$fields);
        $query = "SELECT Users.Username as Maintainer, {$fields} " .
            "FROM Packages LEFT JOIN Users " .
            "ON Packages.MaintainerUID = Users.ID " .
            "WHERE ${where_condition}";
        $result = $this->dbh->query($query);

        if ($result) {
            $resultcount = 0;
            $search_data = array();
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $resultcount++;
                $name = $row['Name'];
                $row['URLPath'] = URL_DIR . substr($name, 0, 2) . "/" . $name . "/" . $name . ".tar.gz";

                /* Unfortunately, mysql_fetch_assoc() returns all fields as
                 * strings. We need to coerce numeric values into integers to
                 * provide proper data types in the JSON response.
                 */
                foreach (self::$numeric_fields as $field) {
                    $row[$field] = intval($row[$field]);
                }

                if ($type == 'info') {
                    $search_data = $row;
                    break;
                }
                else {
                    array_push($search_data, $row);
                }
            }

           if ($resultcount === $MAX_RPC_RESULTS) {
               return $this->json_error('Too many package results.');
           }

            return $this->json_results($type, $resultcount, $search_data);
        }
        else {
            return $this->json_results($type, 0, array());
        }
    }

    /**
     * Parse the args to the multiinfo function. We may have a string or an 
     * array, so do the appropriate thing. Within the elements, both * package 
     * IDs and package names are valid; sort them into the relevant arrays and
     * escape/quote the names.
     * @param $args the arg string or array to parse.
     * @return mixed An array containing 'ids' and 'names'.
     **/
    private function parse_multiinfo_args($args) {
        if (!is_array($args)) {
            $args = array($args);
        }

        $id_args = array();
        $name_args = array();
        foreach ($args as $arg) {
            if (!$arg) {
                continue;
            }
            if (is_numeric($arg)) {
                $id_args[] = intval($arg);
            } else {
                $name_args[] = $this->dbh->quote($arg);
            }
        }

        return array('ids' => $id_args, 'names' => $name_args);
    }

    /**
     * Performs a fulltext mysql search of the package database.
     * @param $keyword_string A string of keywords to search with.
     * @return mixed Returns an array of package matches.
     **/
    private function search($keyword_string) {
        global $MAX_RPC_RESULTS;
        if (strlen($keyword_string) < 2) {
            return $this->json_error('Query arg too small');
        }

        $keyword_string = $this->dbh->quote("%" . addcslashes($keyword_string, '%_') . "%");

        $where_condition = "(Name LIKE {$keyword_string} OR ";
        $where_condition.= "Description LIKE {$keyword_string}) ";
        $where_condition.= "LIMIT {$MAX_RPC_RESULTS}";

        return $this->process_query('search', $where_condition);
    }

    /**
     * Returns the info on a specific package.
     * @param $pqdata The ID or name of the package. Package Query Data.
     * @return mixed Returns an array of value data containing the package data
     **/
    private function info($pqdata) {
        if ( is_numeric($pqdata) ) {
            // just using sprintf to coerce the pqd to an int
            // should handle sql injection issues, since sprintf will
            // bork if not an int, or convert the string to a number 0
            $where_condition = "Packages.ID={$pqdata}";
        }
        else {
            $where_condition = sprintf("Name=%s", $this->dbh->quote($pqdata));
        }
        return $this->process_query('info', $where_condition);
    }

    /**
     * Returns the info on multiple packages.
     * @param $pqdata A comma-separated list of IDs or names of the packages.
     * @return mixed Returns an array of results containing the package data
     **/
    private function multiinfo($pqdata) {
        global $MAX_RPC_RESULTS;
        $args = $this->parse_multiinfo_args($pqdata);
        $ids = $args['ids'];
        $names = $args['names'];

        if (!$ids && !$names) {
            return $this->json_error('Invalid query arguments');
        }

        $where_condition = "";
        if ($ids) {
            $ids_value = implode(',', $args['ids']);
            $where_condition .= "ID IN ({$ids_value}) ";
        }
        if ($ids && $names) {
            $where_condition .= "OR ";
        }
        if ($names) {
            // individual names were quoted in parse_multiinfo_args()
            $names_value = implode(',', $args['names']);
            $where_condition .= "Name IN ({$names_value}) ";
        }

        $where_condition .= "LIMIT {$MAX_RPC_RESULTS}";

        return $this->process_query('multiinfo', $where_condition);
    }

    /**
     * Returns all the packages for a specific maintainer.
     * @param $maintainer The name of the maintainer.
     * @return mixed Returns an array of value data containing the package data
     **/
    private function msearch($maintainer) {
        global $MAX_RPC_RESULTS;
        $maintainer = $this->dbh->quote($maintainer);

        $where_condition = "Users.Username = {$maintainer} ";
        $where_condition .= "LIMIT {$MAX_RPC_RESULTS}";

        return $this->process_query('msearch', $where_condition);
    }

    /**
     * Get all package names that start with $search.
     * @param string $search Search string.
     * @return string The JSON formatted response data.
     **/
    private function suggest($search) {
        $query = 'SELECT Name FROM Packages WHERE Name LIKE ' .
            $this->dbh->quote(addcslashes($search, '%_') . '%') .
            ' ORDER BY Name ASC LIMIT 20';

        $result = $this->dbh->query($query);
        $result_array = array();

        if ($result) {
            $result_array = $result->fetchAll(PDO::FETCH_COLUMN, 0);
        }

        return json_encode($result_array);
    }
}

