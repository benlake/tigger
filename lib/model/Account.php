<?php

/**
 * Represents a vTiger account (Accounts module entity)
 */

class Account
{
    /**
     * Field list is a bit ridiculous, here is a small sample:
     */
    protected $ws_result;

    function __construct($webservice_result = null)
    {
        if ($webservice_result instanceof stdClass)
        {
            $this->ws_result = get_object_vars($webservice_result);
        }
        else
        {
            // fill in placeholders if object created without a valid vtiger result
            $this->ws_result = array('accountname' => 'Unknown');
        }
    }

    public function getId()
    {
        return $this->ws_result['id'];
    }

    public function getNumber()
    {
        return $this->ws_result['account_no'];
    }

    public function getName()
    {
        return $this->ws_result['accountname'];
    }

    /**
     * Return a list of fields to be gathered when querying for accounts
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
            'accountname',
            'account_no',
            );
        if ($as_array) return $r;
        return join(',', $r);
    }
}

