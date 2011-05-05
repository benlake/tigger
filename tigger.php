<?php
/**
 * Tigger, a PHP vTiger cli tool for tracking tickets and entering time
 * @author Ben Lake <me@benlake.org>
 * @license GNU Public License v3 (http://opensource.org/licenses/gpl-3.0.html)
 * @copyright Copyright (c) 2011, Ben Lake
 * @link https://github.com/benlake/tigger
 *
 * This file is part of the Tigger project.
 *
 * Tigger is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Tigger is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Tigger.  If not, see <http://www.gnu.org/licenses/>.
 */

//
// Hard defaults
//
error_reporting(E_ERROR);
ini_set('display_errors', 1);

// libs
set_include_path('./lib:'.get_include_path());
require_once 'share/CliInput.php';
require_once 'share/Getopt.php';
require_once 'share/Console_Table.php';
require_once 'share/Console_Color.php';
// models
require_once 'model/TroubleTicket.php';
require_once 'model/Account.php';

Tigger::init();

if (Tigger::o('debug'))
{
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

if (!Conf::loaded())
{
print <<<WARN
Welcome to Tigger v0.3.1!

You will need to get your "Access Key" from the "My Preferences" page in vTiger.
If you want Tigger to auto-login to vTiger, create the file ~/.tigger that looks
like the following:

    [login]
    host=https://vtiger.speedfc.com
    username=<user>
    access_key=<access key>


WARN;
}

// login to vTiger
command_login();

//
// main loop
//
do
{
    // @todo - we use to run asyncronously, but the addition of readline made that
    // a little trickier. Need to get back to asyncronous runs or maybe just a 'watch'
    // command?

    // awaiting commands
    command();
}
while (true);

// ===========
// = Classes =
// ===========

/**
 * 
 */
class VTiger
{
    protected static $PROTOCOL = 'https://';
    protected static $HOST = 'localhost';
    protected static $SERVICE_ENDPOINT = '/webservice.php';

    /**
     * The key used to login, generated after the challenge token request.
     */
    protected static $SESSION_KEY;

    /**
     * The session identifier, populated upon successful login.
     */
    protected static $SESSION_ID;

    /**
     * The user id as used by vTiger, populated upon successful login.
     */
    protected static $USER_ID;

    /**
     * When the user session is going to expire.
     */
    protected static $SESSION_EXPIRATION;

    /**
     * In-memory object cache. Holds objects retrieved often.
     */
    protected static $memory_cache = array();

    public static function init($host, $proto = 'https', $path = null)
    {
        if (empty($host))
            throw new Exception('No hostname provided.');

        self::$HOST = $host;

        switch ($proto)
        {
        case 'http': self::$PROTOCOL = 'http://'; break;
        default:     self::$PROTOCOL = 'https://'; break;
        }

        if (!empty($path))
        {
            self::$SERVICE_ENDPOINT = $path;
        }
    }

    public static function getUserId()
    {
        return self::$USER_ID;
    }

    //
    // Authentication
    //

    public static function hasSession()
    {
        return !empty(self::$SESSION_ID);
    }

    public static function login($user, $key)
    {
        // get a challange key from the server
        $params = array(
            'operation' => 'getchallenge',
            'username' => $user,
            );
        // @todo - catch some errors? probably best to catch them higher up
        $r = self::execRequest($params);
        if (!is_object($r)) return false;
        if ($r->success !== true) return false;

        // @todo - maybe look at server time and adjust expiration based on how
        //         out of sync we are
        // object(stdClass)#2 (3) {
        //   ["token"]=>
        //   string(13) "4b09fa9b7406e"
        //   ["serverTime"]=>
        //   int(1258945179)
        //   ["expireTime"]=>
        //   int(1258945479)
        // }

        self::$SESSION_EXPIRATION = $r->result->expireTime;
        self::$SESSION_KEY = md5($r->result->token.$key);

        // perform the actual login
        $params = array(
            'operation' => 'login',
            'username' => $user,
            'accessKey' => self::$SESSION_KEY,
            );
        // @todo - catch some errors? probably best to catch them higher up
        $r = self::execRequest($params, 'POST');
        if (!is_object($r)) return false;

        if ($r->success !== true)
        {
            self::$SESSION_KEY = null;
            return false;
        }

        self::$SESSION_ID = $r->result->sessionName;
        self::$USER_ID = $r->result->userId;

        return true;
    }

    public static function maintain_session()
    {
        if (empty(self::$SESSION_ID)) return;

        if (time() > (self::$SESSION_EXPIRATION - 30))
        {
            print "\n[!!] Session expired! You might need to login again if a request fails.\n";
            // @todo - actually renew the session somehow
            self::$SESSION_EXPIRATION += 300;
        }
    }

    //
    // Trouble Tickets
    //

    /**
     * @param string    the ticket number in either its numeric or string form
     * @param boolean   [false] whether to fetch a complete ticket object or
     *                  just enough to be useful (update operations require complete).
     * @throws TicketNumberInvalidException, TicketNotFoundException
     */
    public static function ticket_lookup($ticket_num, $complete = false)
    {
        $ticket_num = Tigger::normalize_ticket($ticket_num);

        if (Tigger::o('debug')) print "[DD] using ticket number: ".$ticket_num."\n";

        $query = 'SELECT '.TroubleTicket::getFields($complete)
            .' FROM HelpDesk'
            .' WHERE ticket_no = \''.$ticket_num.'\''
            .';';

        $params = array(
            'operation' => 'query',
            'query' => $query,
            );
        // @todo - catch some errors? probably best to catch them higher up
        $r = self::execRequest($params);

        if (!empty($r) && $r->success === true
            && count($r->result) > 0)
        {
            if (count($r->result) > 1)
            {
                print "[**] More than one ticket was returned, weird.\n";
            }

            $tt = new TroubleTicket(array_pop($r->result));
            // see if this ticket is being watched
            if (!Tigger::o('no-storage'))
            {
                $tt->setWatched(Tigger::state_is_watching($tt));
            }

            // get a useful account name
            $acct = self::account_lookup($tt->getAccountId());
            if ($acct instanceof Account)
            {
                $tt->setAccount($acct);
            }

            return $tt;
        }

        throw new TicketNotFoundException($ticket_num);
    }

    public static function ticket_get_assigned($additional_tickets = array())
    {
        $query = 'SELECT '.TroubleTicket::getFields()
            .' FROM HelpDesk'
            .' WHERE assigned_user_id = \''.self::$USER_ID.'\''
            .'    AND ticketstatus != \'Closed\'';
        foreach ($additional_tickets as $ticket_num)
        {
            $query .= ' OR ticket_no = \''.$ticket_num.'\'';
        }
        $query .= ' ORDER BY cf_551, ticket_no;';

        $params = array(
            'operation' => 'query',
            'query' => $query,
            );
        // @todo - catch some errors? probably best to catch them higher up
        $r = self::execRequest($params);

        $tickets = array();
        if (!empty($r) && $r->success === true)
        {
            foreach ($r->result as $ti)
            {
                $ticket = new TroubleTicket($ti);

                // see if this ticket is being watched
                if (!Tigger::o('no-storage'))
                {
                    $ticket->setWatched(Tigger::state_is_watching($ticket));
                }

                // get a useful account name
                $acct = self::account_lookup($ticket->getAccountId());
                if ($acct instanceof Account)
                {
                    $ticket->setAccount($acct);
                }

                $tickets[] = $ticket;
            }
        }
        else
            return false;

        return $tickets;
    }

    /**
     * Get the list of available statuses to select from
     * 
     */
    public function ticket_get_status_list()
    {
        // fetch available ticket statuses
        $params = array(
            'operation' => 'describe',
            'elementType' => 'HelpDesk'
            );
        // @todo - catch some errors? probably best to catch them higher up
        // @todo - cache these results for the session
        $r = self::execRequest($params);
        if (!empty($r) && $r->success === true)
        {
            $list = array();
            foreach ($r->result->fields as $k => $v)
            {
                if ($v->name == 'ticketstatus')
                {
                    foreach ($v->type->picklistValues as $l) $list[] = $l->label;
                    break;
                }
            }
            return $list;
        }

        return false;
    }

    /**
     * Set the ticketstatus field to the specified status.
     * @param mixed     A ticket number or TroubleTicket object
     * @param string    A valid status from the HelpDesk objects ticketstatus field
     * @param boolean   true upon success, false otherwise
     */
    public function ticket_set_status($ticket, $status)
    {
        // if we don't have a trouble ticket object, lookup the number
        if (!($ticket instanceof TroubleTicket))
        {
            try {
                $ticket = VTiger::ticket_lookup($ticket_num);
            } catch (Exception $e) {
                Tigger::print_exception($e);
                return false;
            }
        }

        $ticket->setStatus($status);

        $params = array(
            'operation' => 'update',
            'element' => json_encode($ticket->getWebServiceObject()),
            'elementType' => 'HelpDesk',
            );
        $r = self::execRequest($params, 'POST');

        if (!empty($r) && $r->success === true)
        {
            return true;
        }
        return false;
    }

    //
    // Timesheet
    //

    /**
     * Create a time entry against the specified ticket.
     */
    public static function time_entry_create($ticket_num, $ttl_minutes, $date)
    {
        // lookup the ticket
        try {
            $ticket = VTiger::ticket_lookup($ticket_num);
        } catch (Exception $e) {
            Tigger::print_exception($e);
            return false;
        }

        list($year, $month, $day) = explode('-', $date);
        $start_time = mktime(9, 0, 0, $month, $day, $year); // always start at 9AM
        $start_time = date('Y-m-d H:i:s', $start_time);
        $end_time = mktime(9, $ttl_minutes, 0, $month, $day, $year);
        $end_time = date('Y-m-d H:i:s', $end_time);

        $hours = $minutes = 0;
        if ($ttl_minutes >= 60)
        {
            $hours = intval($ttl_minutes / 60);
            $ttl_minutes -= $hours * 60;
        }
        $minutes = intval($ttl_minutes);
        $length_of_time = str_pad($hours, 2, '0', STR_PAD_LEFT)
            .':'.str_pad($minutes, 2, '0', STR_PAD_LEFT)
            .':00';
        $ttl_minutes = null;

        // Required Parameters
        // -------------------
        // tsreltoid [Related to] - value needs to be an id of a HelpDesk object (trouble ticket)
        // assigned_user_id [Assigned to] - user id
        // start [start YYYY-MM-DD HH:II:SS] - the start date and time of the timesheet entry
        // end [end YYYY-MM-DD HH:II:SS] - the end date and time of the timesheet entry
        // totaltime [time in format HHs:MMs:SSs] - the total time between start and end (really? sigh)
        $entry = array(
            'tsreltoid' => $ticket->getId(),
            'tsconcept' => 'Other',
            'assigned_user_id' => self::$USER_ID,
            'start' => $start_time,
            'end' => $end_time,
            'totaltime' => $length_of_time,
            );

        $params = array(
            'operation' => 'create',
            'element' => json_encode($entry),
            'elementType' => 'Timesheet'
            );
        $r = self::execRequest($params, 'POST');

        if ($r->success === true)
        {
            print $length_of_time." has been logged against ticket "
                 .$ticket->getNumber()." on ".$start_time."\n";
        }
        else
        {
            print "[!!] Error creating time entry.\n";
        }

        return $r->success;
    }

    //
    // Development/Debug
    //

    public static function debug_listtypes()
    {
        $params = array(
            'operation' => 'listtypes',
            );
        // @todo - catch some errors? probably best to catch them higher up
        $r = self::execRequest($params);
        var_dump($r);

        return $r->success;
    }

    public static function debug_describe($type)
    {
        $params = array(
            'operation' => 'describe',
            'elementType' => ucfirst($type),
            );
        // @todo - catch some errors? probably best to catch them higher up
        $r = self::execRequest($params);
        var_dump($r);

        return $r->success;
    }

    public static function account_lookup($account_id)
    {
        // check cache
        if (array_key_exists('accounts', self::$memory_cache)
            && array_key_exists($account_id, self::$memory_cache['accounts']))
        {
            return self::$memory_cache['accounts'][$account_id];
        }

        $query = 'SELECT '.Account::getFields()
            .' FROM Accounts'
            .' WHERE id = \''.$account_id.'\''
            .';';

        $params = array(
            'operation' => 'query',
            'query' => $query,
            );
        // @todo - catch some errors? probably best to catch them higher up
        $r = self::execRequest($params);

        if (!empty($r) && $r->success === true
            && count($r->result) > 0)
        {
            if (count($r->result) > 1)
            {
                print "[**] More than one account was returned, weird.\n";
            }

            $acct = new Account(array_pop($r->result));

            // cache this object
            if (!array_key_exists('accounts', self::$memory_cache))
                self::$memory_cache['accounts'] = array();
            self::$memory_cache['accounts'][$acct->getId()] = $acct;

            return $acct;;
        }
    }

    //
    // Utilities
    //

    protected static function execRequest($params, $method = 'GET')
    {
        if (Tigger::o('no-transmit'))
        {
            print "[!!] vTiger transmissions are currently disabled.\n";
            return false;
        }

        // refresh session
        // @todo - vTiger has this web service session, but it doesn not appear
        // to mean anything. This is here if that ever gets some teeth
        //VTiger::maintain_session();

        // if we have a session established, go ahead and send the session id
        if (!empty(self::$SESSION_ID))
        {
            $params['sessionName'] = self::$SESSION_ID;
        }

        $base_url = self::$PROTOCOL.self::$HOST.self::$SERVICE_ENDPOINT;

        $context = array(
            'http' => array(
                // odd - vtiger doesn't like headers...
                //'header' => "Content-Type: text/html\r\n",
                'user_agent' => 'Tigger vTiger Client',
                'max_redirects' => 1,
                'timeout' => 20
                )
            );

        if ($method == 'POST')
        {
            $context['http']['method'] = 'POST';
            $context['http']['content'] = http_build_query($params);
            // odd - vtiger doesn't like headers...
            $context['http']['header'] = 
                "Content-Type: application/x-www-form-urlencoded\r\n".
                "Content-Length: ".strlen($context['http']['content'])."\r\n";
            $url = $base_url;
        }
        else // GET
        {
            $context['http']['method'] = 'GET';
            $url = $base_url.'?'.http_build_query($params);
        }

        if (Tigger::o('debug')) print "[DD] URL: ".$url."\n";
        if (Tigger::o('debug')) print "[DD] context: ".var_export($context, true)."\n";

        $context = stream_context_create($context);
        $response = @file_get_contents($url, null, $context);

        // check for an error
        if (!isset($http_response_header)
            || strpos($http_response_header[0], '200 OK') === false)
        {
            throw new VTigerRequestFailedException($url);
        }

        if (preg_match('/(Uncaught exception)/', $response) == 1)
        {
            print "\n[!!] vTiger ERROR\n".$response."\n\n";
            return false;
        }

        // @todo - evolve response into a more usable object
        $response = json_decode($response);

        if (Tigger::o('debug'))
        {
            print "vTiger decoded Response:\n-----------------\n";
            var_dump($response);
            print "-----------------\n";
        }

        if ($response === null)
            throw new Exception('Server replied with an invalid response');

        return $response;
    }
}

/**
 * 
 */
class Conf
{
    const FILENAME = '.tigger';

    protected static $config = array();

    public static function load($root)
    {
        $path = $root.'/'.Conf::FILENAME;

        if (!file_exists($path)) return false;

        // @todo - read and parse the file
        self::$config = parse_ini_file($path, true);

        if (Tigger::o('debug'))
        {
            print "Loaded configuration:\n";
            print_r(self::$config);
            print "\n";
        }

        return true;
    }

    public static function loaded()
    {
        return !empty(self::$config);
    }

    /**
     * Fetch a configuration value.
     * @param string    option path in the form section/option
     */
    public static function get($v)
    {
        list($section, $option) = explode('/', $v);

        if (!array_key_exists($section, self::$config)) return null;
        if (!array_key_exists($option, self::$config[$section])) return null;
        return self::$config[$section][$option];
    }
}

/**
 * 
 */
class Tigger
{
    /**
     * SQLite database file for maintaining state information
     */
    const STATE_FILE = '.tiggerdb.sqlite';

    /**
     * SQLiteDatabase object used to maintain state
     */
    protected static $state;

    /**
     * Zend_Console_Getopt object
     */
    protected static $opts;

    protected static $ticket_selected;

    public static function init()
    {
        try {
            self::$opts = new Zend_Console_Getopt(array(
                'home=s'        => 'Specify the home directory to use. Will use HOME environment variable if not specified.',
                'host=s'        => 'Alternate vTiger host (specify http://<host> otherwise https will be used)',
                'help|h'        => 'Print option help',
                'debug|d'       => 'Enable debug output',
                'no-transmit|z' => 'Disable communication with vTiger server',
                'no-storage|x'  => 'Disable use of SQLite for maintaining local state'
            ));
            self::$opts->parse();

            if (self::$opts->getOption('help'))
                throw new Zend_Console_Getopt_Exception(null, self::$opts->getUsageMessage());

        } catch (Zend_Console_Getopt_Exception $e) {
            print $e->getUsageMessage();
            exit(1);
        }

        // if no home directory specified, try to determine one
        if (self::o('home'))
            $home = self::o('home');
        else
            $home = getenv('HOME');
        if (self::o('debug')) print "[DD] Using home [".$home."]\n";
        if (empty($home))
        {
            print "[EE] Unable to determine your home directory. Try --home=<path>\n";
            exit(3);
        }

        // check for required version/extensions
        if (!self::check_compatibility()) exit(2);

        // load configuration
        Conf::load($home);

        // load/init state maintenance
        if (!self::o('no-storage')) self::state_init($home);

        // prepare web service utility
        $proto = 'https';
        if (self::o('host'))
            $host = self::o('host');
        elseif (Conf::get('login/host'))
            $host = Conf::get('login/host');
        else
        {
            print "[EE] No host defined! Use option --host or config.\n";
            exit(4);
        }

        // check for protocal
        if (preg_match('/(https?):\/\/(.*)/i', $host, $m) == 1)
        {
            $proto = $m[1];
            $host = $m[2];
        }

        if (self::o('debug')) print "[DD] Using host [".$host."] and protocol [".$proto."]\n";
        VTiger::init($host, $proto);
    }

    public static function check_compatibility()
    {
        // version
        if (version_compare(PHP_VERSION, '5.0.0', '<'))
        {
            print "[EE] PHP 5.0.0 or greater is required\n";
            return false;
        };

        // ssl
        if (!extension_loaded('openssl'))
        {
            print "[EE] OpenSSL is required to utilize HTTP over SSL.\n";
            return false;
        }

        // readline
        if (!extension_loaded('readline') || !function_exists('readline_callback_handler_install'))
        {
            print "[EE] GNU readline extension required.\n"
                ."(I could make it not required but it is just too dam nice).\n";
            return false;
        }

        if (!self::o('no-storage'))
        {
           if (!extension_loaded('sqlite') || !class_exists('SQLiteDatabase'))
           {
               print "[EE] SQLite is required to maintain state. Use -x to disable\n";
               return false;
           }
        }

        return true;
    }

    public static function o($opt)
    {
        return self::$opts->getOption($opt);
    }

    public static function getCommandPrompt()
    {
        $path = null;
        $prefix = 'tigger';
        $postfix = '=>';
    
        $path = $prefix;
        if (self::isTicketSelected())
        {
            $path .= '/'.self::getSelectedTicket();
        }
        $path .= $postfix;

        return $path;
    }

    // ====================
    // = Ticket Selection =
    // ====================

    public static function isTicketSelected()
    {
        return !empty(self::$ticket_selected);
    }

    public static function getSelectedTicket()
    {
        return self::$ticket_selected;
    }

    public static function clearSelectedTicket()
    {
        self::$ticket_selected = null;
    }

    public static function setSelectedTicket($ticket_num)
    {
        self::$ticket_selected = $ticket_num;
    }

    // ====================
    // = State Management =
    // ====================

    protected static function state_init($root)
    {
        $path = $root.'/'.self::STATE_FILE;

        self::$state = new SQLiteDatabase($path, '0700', $err);
        if (self::state_err_init($err)) return;

        // initialize or update state file if needed
        self::state_upgrade_file();
    }

    protected static function state_err_init($err)
    {
        if (!empty($err))
        {
            print "[EE] Unable to init state. Disabling for this session.\n";
            print "[EE] ".$err."\n";
            self::$state = null;
            return true;
        }
        return false;
    }

    /**
     * Create or build the necessary state file structure.
     */
    protected static function state_upgrade_file()
    {
        // latest full build
        $latest = "
            CREATE TABLE settings (key varchar(20), value varchar(100));
            INSERT INTO settings VALUES ('version', 1);

            CREATE TABLE ticket_watch_list (ticket_num varchar(10) not null);
            CREATE TABLE ticket_time_entry (ticket_num varchar(10) not null, started_at datetime, ended_at datetime, sent boolean default false);
            ";

        // check current version
        $version = self::state_get_setting('version');
        if ($version === false)
        {
            if (self::o('debug'))
                print "[DD] No previous state found, initializing...\n";

            // we need to construct the state file from scratch
            if (!self::$state->queryExec($latest, $err))
            {
                self::state_err_init($err);
                return false;
            }
        }

        $version = self::state_get_setting('version');
        if (self::o('debug'))
            print "[DD] State structure version at: ".$version."\n";

        // sql statements needed for upgrade to "to" version
        $updates = array(
            1 => null,
            // 2 => 'SQL'
            );

        // if we are not new, compare versions and determine updates to run
        $latest_version = array_pop(array_keys($updates));
        for (;$version < $latest_version;)
        {
            ++$version;
            if (!self::$state->queryExec($updates[$version], $err))
            {
                print "[EE] Failed to upgrade state structure. Disabling for this session.\n";
                print "[EE] ".$err."\n";
                self::$state = null;
                break;
            }
        }

        return true;
    }

    protected static function state_get_setting($k)
    {
        $sql = "SELECT value FROM settings WHERE key = '".$k."'";
        $rs = @self::$state->query($sql, SQLITE_ASSOC, $err);
        if (!($rs instanceof SQLiteResult) || !$rs->valid()) return false;
        return $rs->fetchSingle();
    }

    /**
     * @todo test this method
     * @bug
     * @untested
     */
    protected static function state_set_setting($k, $v)
    {
        $sql = "UPDATE settings SET value = '".$v."' WHERE key = '".$k."'";
        @self::$state->query($sql, $err);
        // did the value already exist? if not, add it
        if (!empty($err))
        {
            $sql = "INSERT INTO settings VALUES ('".$k."', '".$v."');";
            @self::$state->query($sql, $err);
        }
    }

    // end state management

    // ===========
    // = Visuals =
    // ===========

    public static function print_ticket(TroubleTicket $t)
    {
        // construct the tabular output
        $tbl = new Console_Table(
            CONSOLE_TABLE_ALIGN_LEFT,
            CONSOLE_TABLE_BORDER_ASCII,
            1
            );

        $tbl->addRow(array('Ticket #', $t->getNumber()));
        $tbl->addRow(array('Account', $t->getAccountName()));
        $tbl->addRow(array('Title', $t->getTitle()));
        $tbl->addRow(array('Status', $t->getStatus()));
        $tbl->addRow(array('Type', $t->getType()));
        $tbl->addRow(array('Severity', $t->getSeverity()));
        $tbl->addRow(array('Billable', $t->getBillingType()));
        $tbl->addRow(array('Priority', $t->getPriority()));

        print $tbl->getTable();
    }

    public static function show_ticket($ticket_num, $return_complete = false)
    {
        try {
            $ticket = VTiger::ticket_lookup($ticket_num, $return_complete);
        } catch(Exception $e) {
            Tigger::print_exception($e);
            return false;
        }

        if ($ticket instanceof TroubleTicket)
        {
            self::print_ticket($ticket);
            return $ticket;
        }
    }

    public static function print_exception(Exception $e)
    {
        switch (get_class($e))
        {
        case 'TicketNotFoundException':
            $msg = "[!!] Ticket ".$e->getMessage()." was not found.";
            break;
        case 'TicketNumberInvalidException':
            $msg = "[!!] Invalid ticket number ".$e->getMessage();
            break;
        default:
            $msg = get_class($e).' '.$e->getMessage();
            break;
        }

        print $msg."\n";
    }

    public static function list_tickets($tickets)
    {
        // construct the tabular output
        $tbl = new Console_Table(
            CONSOLE_TABLE_ALIGN_LEFT,
            null,
            1,
            null,
            true
            );

        $tbl->setHeaders(
            array('O_o', '#', 'Account', 'Title', 'Status', 'LOE', 'Due Date', /*'Type', 'Severity',*/ 'Billable', 'Priority')
        );

        // define data formatters
        $cb1 = array('Tigger', 'table_formatter_colorduedate');
        $tbl->addFilter(6 /* due date */, $cb1); // highlight result codes
        $cb2 = array('Tigger', 'table_formatter_trim60');
        $tbl->addFilter(3 /* title */, $cb2);

        foreach ($tickets as $t)
        {
            // determine if the ticket is in the watch list, if so indicate. Also
            // indicate when the ticket is on the watch list and not currently assigned
            // to you
            $icon = null;
            if ($t->isWatched())
            {
                if (VTiger::getUserId() == $t->getAssignedUserId())
                    $icon = ' * ';
                else
                    $icon = ' ! ';
            }

            $tbl->addRow(array(
                $icon,
                $t->getNumber(),
                $t->getAccountName(),
                $t->getTitle(),
                $t->getStatus(),
                $t->getLOE(),
                $t->getDueDate(),
                // $t->getType(),
                // $t->getSeverity(),
                $t->getBillingType(),
                $t->getPriority(),
                ));
        }

        print $tbl->getTable();
        print "Legend: * = watched, ! = watched and not currently assigned\n";
    }

    public static function table_formatter_trim60($v)
    {
        if (strlen($v) > 60)
            return substr($v, 0, 57).'...';

        return substr($v, 0, 60);
    }

    public static function table_formatter_colorduedate($v)
    {
        if (empty($v))
        {
            return Console_Color::convert($v.'%n');
        }

        $due = strtotime($v);
        $now = time();
        $diff = $due - $now;
        $day = 3600 * 24;

        $v = ' '.$v.' ';
        if ($diff > 0) // not past due
        {
            // within 2 days - warn
            if ($diff / $day <= 2)
            {
                return Console_Color::convert('%3%k'.$v.'%n');
            }
            else // greater than 2 days - safe
            {
                return Console_Color::convert('%n'.$v.'%n');
            }
        }
        else // past due
        {
            return Console_Color::convert('%1%w'.$v.'%n');
        }
    }

    // =========
    // = Utils =
    // =========

    /**
     * @throws TicketNumberInvalidException
     */
    public static function normalize_ticket($ticket_num)
    {
        $ticket_num = str_ireplace('t', '', $ticket_num);
        $ticket_num = 'TT'.$ticket_num;

        if (preg_match('/^TT\d+$/', $ticket_num) != 1)
            throw new TicketNumberInvalidException($ticket_num);

        return $ticket_num;
    }

    // =========================
    // = Local Data Management =
    // =========================

    public static function state_watch_ticket($ticket_num)
    {
        if (Tigger::state_is_watching($ticket_num)) return null;

        if ($ticket_num instanceof TroubleTicket)
            $ticket_num = $ticket_num->getNumber();

        $sql = "INSERT INTO ticket_watch_list VALUES ('".$ticket_num."')";
        // @todo - check for an "execute" method as opposed to query
        $rs = self::$state->query($sql);
        if (!($rs instanceof SQLiteResult)) return false;
        return true;
    }

    public static function state_unwatch_ticket($ticket_num)
    {
        if (!Tigger::state_is_watching($ticket_num)) return null;

        if ($ticket_num instanceof TroubleTicket)
            $ticket_num = $ticket_num->getNumber();

        $sql = "DELETE FROM ticket_watch_list WHERE ticket_num = '".$ticket_num."'";
        // @todo - check for an "execute" method as opposed to query
        $rs = self::$state->query($sql);
        if (!($rs instanceof SQLiteResult)) return false;
        return true;
    }

    public static function state_is_watching($ticket_num)
    {
        if ($ticket_num instanceof TroubleTicket)
            $ticket_num = $ticket_num->getNumber();

        $sql = "SELECT ticket_num FROM ticket_watch_list WHERE ticket_num = '".$ticket_num."'";
        $rs = @self::$state->query($sql, SQLITE_ASSOC, $err);
        if (!($rs instanceof SQLiteResult) || !$rs->valid()) return false;
        $result = $rs->fetchSingle();
        return !empty($result);
    }

    public static function state_get_watched()
    {
        $list = array();

        $sql = "SELECT * FROM ticket_watch_list ORDER BY ticket_num";
        $rs = @self::$state->query($sql, SQLITE_ASSOC, $err);
        if (!($rs instanceof SQLiteResult) || !$rs->valid()) return $list;

        while ($rs->valid())
            $list[] = array_shift($rs->fetch());

        return $list;
    }
}

// =============
// = Functions =
// =============

function command()
{
    $input = CliInput::prompt(Tigger::getCommandPrompt().' ', 0, 33);

    // nothing changed, ignore
    if (empty($input)) return false;

    // cleanup
    $input = str_replace("\t", ' ', $input);
    $args = array();
    foreach (explode(' ', $input) as $k => $v) { if(strlen($v) > 0) $args[] = $v; }

    if (Tigger::o('debug')) print "[DD] parsed args: ".join($args, ' / ')."\n";

    // determine command vs args
    $command = array_shift($args);

    $command = getalias($command);
    $func = 'command_'.$command;

    if (Tigger::o('debug')) print "[DD] command: ".$func."\n";

    if (($r = function_exists($func)))
        call_user_func_array($func, $args);

    return $r;
}

function getalias($command)
{
    switch ($command)
    {
    case 'bye':
    case 'exit':
    case 'quit':
    case 'q':
        return 'quit';
        break;
    case '?':
        return 'help';
        break;
    }

    return $command;
}

function usage($function = '*')
{
    $u = array(
        'login' => "Login to vtiger\n"
             ."  Usage: login [force]\n",
        'help' => "Show available commands\n",
        'quit' => "Quit Tigger\n",

        'time' => "Create a time entry record against the specified ticket\n"
             ."  Usage: time [<ticket number> | - ] <time> <date>\n",

        'show' => "Lookup basic information about a ticket. Name, status, type, etc.\n"
             ."  Usage: show [<ticket number>]\n",

        'status' => "Change the status of a ticket\n"
            ."  Usage: status [ <ticket number> | - ] [<status choice index> | <status_name> | <status alias>]\n",

        'list' => "Show a list of currently assigned and watched tickets\n"
            ."  Usage: list\n",

        'watch' => "Add a ticket to your watch list. The ticket will remain in your 'list'\n"
            ."until unwatched.\n"
            ."  Usage: watch [<ticket number>]\n",

        'unwatch' => "Remove a ticket from your watch list\n"
            ."  Usage: unwatch [<ticket number>]\n",

        'set' => "Set or clear the ticket to be used as the target when not specified as an argument\n"
            ."  Usage: set [<ticket number> | ]\n",
        );

    // function usage detail
    $ud = array(
        'login' => null,
        'help' => null,
        'quit' => null,
        'time' =>
              "  [ <ticket number> | - ] - ticket number (ie. with or w/o a TT/tt prefix) or\n"
             ."          a dash (-) to use the current target ticket (see set command)\n"
             ."  <time> - a numeric value representing the total time to log.\n"
             ."           If the value contains no decimal place it is assumed to be minutes.\n"
             ."           If it contains a decimal, it will be construed as hours with fractional\n"
             ."           minutes.\n\n"
             ."  Examples: 10 = 10 minutes\n"
             ."            120 = 120 minutes\n"
             ."            2.0 = 2 hours\n"
             ."            10.0 = 10 hours\n"
             ."            10.5 = 10 hours and 30 minutes\n\n"
             ."  <date> - YYYYMMDD|YYYY-MM-DD the time should be logged on\n",

        'show' =>
              "  [<ticket number>] - ticket number (ie. with or w/o a TT/tt prefix).\n"
             ."          If ticket number is not provided, the target ticket is used.",

        'status' =>
             "  [ <ticket number> | - ] - ticket number (ie. with or w/o a TT/tt prefix) or\n"
            ."          a dash (-) to use the current target ticket (see set command)\n"
            ."  [<status choice index> | <status name> | <status alias>] - if not provided a list will be prompted.\n"
            ."     Otherwise value can be a numeric index value from the choice list,\n"
            ."     the vTiger display namem or an alias as defined below.\n"
            ."     If using the vTiger display name, use underscores in place of spaces.\n"
            ."     Aliases:\n"
            ."       assigned - Assigned\n"
            ."       hold - On Hold\n"
            ."       qa - Awaiting QA\n"
            ."       rework - QA Rework\n"
            ."       rw - QA Rework\n"
            ."       closed - Closed\n"
            ."       ip - In Progress\n"
            ."       now - In Progress\n",

        'list' => null,
        'watch' =>
             "  [<ticket number>] - ticket number (ie. with or w/o a TT/tt prefix)\n"
            ."          If ticket number is not provided, the target ticket is used.",
        'unwatch' =>
             "  [<ticket number>] - ticket number (ie. with or w/o a TT/tt prefix)\n"
            ."          If ticket number is not provided, the target ticket is used.",
        'set' =>
             "  <ticket number> - ticket number (ie. with or w/o a TT/tt prefix)\n"
            ."                  - leave empty to clear the current ticket\n",
        );

    if ($function == '*') return $u;
    if ($function == 'names') return array_keys($u);

    // rip off command_
    $function = str_replace('command_', '', $function);

    if (array_key_exists($function, $u)) return $u[$function].$ud[$function];

    return "[II] Command not found.\n";
}

/**
 * Provide a command completion list to readline.
 * @todo - provide "in command" argument completion
 */
function usage_autocomplete($input, $index)
{
    return usage('names');
}

// ============
// = Commands =
// ============

/**
 * @todo respond to ctrl-d for exiting
 */
function command_quit()
{
    print "Goodbye\n";
    exit(0);
}

function command_help($cmd = null)
{
    if (empty($cmd))
    {
        print "Available commands: \n\n";
        foreach (usage('*') as $c => $d) print $c.' - '.$d."\n";
        return;
    }
    print usage($cmd);
    return;
}

function command_login($force = false)
{
    if (is_string($force)) $force = true;

    if (Tigger::o('debug'))
    {
        print "Forcing: ".($force ? 'Yes' : 'No')."\n";
    }

    print "Login to vTiger\n";

    if (Conf::get('login/username') !== null
        && !$force)
    {
        print "Using configured username: ".Conf::get('login/username')."\n";
        $user = Conf::get('login/username');
    }
    else
        $user = CliInput::prompt_input('username: ');

    if (Conf::get('login/access_key') !== null
        && !$force)
    {
        print "Using configured access key: ".Conf::get('login/access_key')."\n";
        $key = Conf::get('login/access_key');
    }
    else
        $key = CliInput::prompt_input('access key: ');

    try
    {
        $r = VTiger::login($user, $key);
        if (!$r)
        {
            print "[!!] Login failed.\n";
            return false;
        }
    }
    catch (VTigerRequestFailedException $e)
    {
        print "[EE] Communication failure\n";
        exit(500);
    }

    // clear the screen
    #passthru("clear");

    return true;
}

/**
 * Log time against a specified ticket, optionally on a day other than today.
 * @todo - allow multiple tickets to be specified in comma delimited format
 */
function command_time($ticket_num = null, $time = null, $date = null)
{
    // use selected if request
    if ($ticket_num == '-' && Tigger::isTicketSelected())
        $ticket_num = Tigger::getSelectedTicket();

    if (empty($date)) $date = date('Y-m-d');

    $date_valid = true;
    if (preg_match('/(\d{4})\-?(\d{2})\-?(\d{2})/', $date, $m) != 1) $date_valid = false;
    if (!checkdate($m[2], $m[3], $m[1])) $date_valid = false;
    unset($m);

    // check for required arguments
    if (Tigger::show_ticket($ticket_num) === false || !is_numeric($time) || !$date_valid)
    {
        print usage(__FUNCTION__);
        return;
    }

    // standardize date format
    if (preg_match('/(\d{4})(\d{2})(\d{2})/', $date, $m) == 1)
    {
        $date = substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);
    }

    // detrmine length of time
    // minutes = no decimal point
    // hours = decimal point
    if (strpos($time, '.') !== false)
    {
        // hours into minutes
        $time = ((float)$time) * 60;
    }

    // @todo - provide means of disabling confirmation
    if (!CliInput::prompt_confirm('You are about to log '.$time.' minutes on '.$date.' against the above ticket. Ok?'))
        return false;

    if (Tigger::o('no-transmit')) print "[II] vTiger transmission disabled.\n";

    VTiger::time_entry_create($ticket_num, $time, $date);

    return true;
}

function command_show($ticket_num = null)
{
    // use selected if none provided
    if (empty($ticket_num) && Tigger::isTicketSelected())
        $ticket_num = Tigger::getSelectedTicket();

    // check for required arguments
    if (empty($ticket_num))
    {
        print usage(__FUNCTION__);
        return;
    }

    Tigger::show_ticket($ticket_num);
}

function command_list()
{
    $tickets = array();

    // add in watched only tickets
    if (!Tigger::o('no-storage'))
    {
        $tickets = Tigger::state_get_watched();
    }

    $tickets = VTiger::ticket_get_assigned($tickets);
    if ($tickets === false)
    {
        print "[EE] An error was encountered while attempting to retrieve your tickets.\n";
        return false;
    }
    elseif (is_array($tickets) && empty($tickets))
    {
        print "[II] No tickets found.\n";
        return false;
    }

    Tigger::list_tickets($tickets);
}

function command_status($ticket_num = null, $choice = null)
{
    // use selected if request
    if ($ticket_num == '-' && Tigger::isTicketSelected())
        $ticket_num = Tigger::getSelectedTicket();

    $ticket = Tigger::show_ticket($ticket_num, true);

    $status_list = VTiger::ticket_get_status_list();
    if ($status_list === false)
    {
        print "[EE] Unable to retrive available ticket statuses\n";
        return false;
    }

    if ($choice === null)
    {
        $choice = CliInput::prompt_choice('Select a status to change to:', $status_list);
        if ($choice === false)
            return false;
    }
    else
    {
        // accept numeric and non-numeric status choice
        if (preg_match('/\d{1,2}/', $choice) != 1)
        {
            // define some shortcut status names
            $alias = array(
                'assigned' => 'Assigned',
                'hold' => 'On Hold',
                'qa' => 'Awaiting QA',
                'rework' => 'QA Rework',
                'rw' => 'QA Rework',
                'closed' => 'Closed',
                'ip' => 'In Progress',
                'now' => 'In Progress',
                );
            if (array_key_exists($choice, $alias))
                $choice = $alias[$choice];

            $choice = str_replace('_', ' ', $choice);
            $idx = array_search($choice, $status_list);
            if ($idx !== false)
                $choice = $idx;
            else
                $choice = false;
        }
        unset($idx);

        if ($choice === false || !array_key_exists($choice, $status_list))
        {
            print "[EE] The chosen status index (".$choice.") is invalid\n";
            return false;
        }
    }

    // check if the user selected the status the ticket is already on
    if ($ticket->getStatus() == $status_list[$choice])
    {
        print "[II] Ticket already in the selected status\n";
        return false;
    }

    if (!Tigger::o('quiet')) print "Changing status to: ".$status_list[$choice]."\n";

    if (!VTiger::ticket_set_status($ticket, $status_list[$choice]))
    {
        print "[EE] Failed to change ticket status\n";
        return false;
    }

    Tigger::print_ticket($ticket);
}

function command_watch($ticket_num = null)
{
    // use selected if none provided
    if (empty($ticket_num) && Tigger::isTicketSelected())
        $ticket_num = Tigger::getSelectedTicket();

    $ticket = Tigger::show_ticket($ticket_num);
    $ticket_num = $ticket->getNumber();

    $r = Tigger::state_watch_ticket($ticket_num);
    if ($r === true)
    {
        print "[II] Now watching ticket ".$ticket_num."\n";
    }
    elseif ($r === false)
    {
        print "[EE] Failed to watch ticket ".$ticket_num."\n";
    }
    else
    {
        print "[WW] Ticket ".$ticket_num." is already being watched\n";
    }
    return $r;
}

function command_unwatch($ticket_num = null)
{
    // use selected if none provided
    if (empty($ticket_num) && Tigger::isTicketSelected())
        $ticket_num = Tigger::getSelectedTicket();

    try {
        $ticket_num = Tigger::normalize_ticket($ticket_num);
    } catch (Exception $e) {
        Tigger::print_exception($e);
        return false;
    }

    $r = Tigger::state_unwatch_ticket($ticket_num);
    if ($r === true)
    {
        print "[II] Ticket ".$ticket_num." is no longer being watched\n";
    }
    elseif ($r === false)
    {
        print "[EE] Unable to stop watching ticket ".$ticket_num."\n";
    }
    else
    {
        print "[WW] Ticket ".$ticket_num." was not being watched\n";
    }
    return $r;
}

function command_set($ticket_num = null)
{
    if (empty($ticket_num))
    {
        Tigger::clearSelectedTicket();
    }
    else
    {
        $ticket_num = Tigger::normalize_ticket($ticket_num);
        Tigger::setSelectedTicket($ticket_num);
    }
}

//
// Debug commands
//

function command_debug_listtypes()
{
    var_dump(VTiger::debug_listtypes());
}

function command_debug_describe($type = null)
{
    if (empty($type))
    {
        print "[!!] Usage: ".substr(__FUNCTION__, 8)." <type>\n"
             ."     * Use debug_listtypes to get a list of types.\n";
        return false;
    }

    var_dump(VTiger::debug_describe($type));
}

// ==============
// = Exceptions =
// ==============

class VTigerRequestFailedException extends Exception {}

class TicketNumberInvalidException extends Exception {}
class TicketNotFoundException extends Exception {}
