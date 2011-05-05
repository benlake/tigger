<?php

/**
 * Represents a vTiger trouble ticket (HelpDesk module entity) with some of
 * SpeedFC's custom fields defined for accessibility.
 */
class TroubleTicket
{
    /**
     *  The complete result list for a select all as of 11/24/2009
     *    ["cf_539"]=>
     *    string(6) "Hourly"
     *    ["cf_549"]=>
     *    string(1) "0"
     *    ["cf_550"]=>
     *    string(1) "0"
     *    ["cf_551"]=>
     *    string(3) "101"
     *    ["cf_552"]=>
     *    string(1) "0"
     *    ["cf_553"]=>
     *    string(0) ""
     *    ["cf_554"]=>
     *    string(0) ""
     *    ["cf_555"]=>
     *    string(0) ""
     *    ["cf_556"]=>
     *    string(0) ""
     *    ["cf_558"]=>
     *    string(0) ""
     *    ["cf_560"]=>
     *    string(0) ""
     *    ["cf_561"]=>
     *    string(0) ""
     *    ["cf_562"]=>
     *    string(0) ""
     *    ["cf_564"]=>
     *    string(1) "0"
     *    ["cf_565"]=>
     *    string(18) "Problem Submission"
     *    ["cf_566"]=>
     *    string(0) ""
     *    ["cf_569"]=>
     *    string(4) "0.00"
     *    ["cf_591"]=>
     *    string(4) "High"
     *    ["createdtime"]=>
     *    string(19) "2009-11-23 16:33:02"
     *    ["description"]=>
     *    string(140) "kjsdnskdjndjkndkj"
     *    ["hours"]=>
     *    string(1) "0"
     *    ["modifiedtime"]=>
     *    string(19) "2009-11-23 19:47:30"
     *    ["parent_id"]=>
     *    string(5) "3x151"
     *    ["ticketseverities"]=>
     *    string(15) "Default Request"
     *    ["assigned_user_id"]=>
     *    string(7) "19x8261"
     *    ["solution"]=>
     *    string(0) ""
     *    ["ticketstatus"]=>
     *    string(11) "In Progress"
     *    ["ticket_no"]=>
     *    string(6) "TT9886"
     *    ["ticket_title"]=>
     *    string(45) ""
     *    ["update_log"]=>
     *    string(350) "dflkndfmfldkmfdkl"
     *    ["id"]=>
     *    string(7) "9x13099"
     */
    protected $ws_result;

    /**
     * @var Account object
     */
    protected $account;

    /**
     * @var boolean whether this ticket is being watched or not
     */
    protected $watched = false;

    function __construct($webservice_result)
    {
        $this->ws_result = get_object_vars($webservice_result);
        $this->account = new Account;
    }

    public function getWebServiceObject()
    {
        return $this->ws_result;
    }

    public function getId()
    {
        return $this->ws_result['id'];
    }

    public function getAccountId()
    {
        return $this->ws_result['parent_id'];
    }

    public function getAssignedUserId()
    {
        return $this->ws_result['assigned_user_id'];
    }

    public function getAccountName()
    {
        return $this->account->getName();
    }

    public function getNumber()
    {
        return $this->ws_result['ticket_no'];
    }

    public function getTitle()
    {
        return $this->ws_result['ticket_title'];
    }

    public function getStatus()
    {
        return $this->ws_result['ticketstatus'];
    }

    public function getType()
    {
        return $this->ws_result['cf_565'];
    }

    public function getSeverity()
    {
        return $this->ws_result['ticketseverities'];
    }

    public function getBillingType()
    {
        return $this->ws_result['cf_539'];
    }

    public function getPriority()
    {
        return $this->ws_result['cf_551'];
    }

    public function getDueDate()
    {
        return $this->ws_result['cf_555'];
    }

    public function getLOE()
    {
        return $this->ws_result['hours'];
    }

    public function isWatched()
    {
        return $this->watched;
    }

    /**
     * Return a list of fields to be gathered when querying for trouble tickets
     * that this object can utilize.
     */
    public static function getFields($complete = false, $as_array = false)
    {
        // attempted to limit the fields so the data exchange was smaller, but for
        // update operations ALL of the fields must be echo'd back even if only
        // updating a single field. So for now grab everything.
        if ($complete)
            return '*';

        $r = array(
            'id',
            'parent_id',
            'ticket_no',
            'ticket_title',
            'ticketseverities',
            'description',
            'createdtime',
            'modifiedtime',
            'ticketstatus',
            'assigned_user_id',
            'cf_565', // type
            'cf_539', // billable
            'cf_551', // priority
            'cf_555', // due date
            'hours', // Hours (LOE)
            );
        if ($as_array) return $r;
        return join(',', $r);
    }

    public function setAccount(Account $a)
    {
        $this->account = $a;
    }

    public function setStatus($v)
    {
        $this->ws_result['ticketstatus'] = $v;
    }

    public function setWatched($v)
    {
        $this->watched = (bool) $v;
    }
}

