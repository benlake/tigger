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

