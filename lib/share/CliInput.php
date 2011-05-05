<?php
/**
 * @author Ben Lake <me@benlake.org>
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (c) 2011, Ben Lake
 * @link http://benlake.org/projects/show/cliinput
 *
 * CliInput is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * CliInput is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with CliInput.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Assists with acquiring user input on the console. Uses advanced readline methods to provide history,
 * tab completion, command navigation, etc. Automatically false back into simple input mode if readline
 * is not available.
 *
 * @package cli
 * @version 0.1
 */
class CliInput
{
    /**
     * has input been captured (irrespective of an actual value)
     */
    protected static $captured = false;
    /**
     * The last value captured (could be empty)
     */
    protected static $last_captured;

    public static function prompt($prompt, $block_timeout = 60, $max_len = 8191, $history = true)
    {
        static $change = 1;

        // setup for blocking
        $milli_timeout = 0;
        $sec_timeout = $block_timeout;
        stream_set_blocking(STDIN, true);

        // setup for non-blocking mode
        if ($block_timeout == 0)
        {
            // allow the application to run while waiting for input
            if (!stream_set_blocking(STDIN, false)) abort('Unable to unblock stdin.');

            $milli_timeout = 200000;
            $sec_timeout = 0;
        }

        if (!empty($change))
        {
            $change  = null;

            readline_callback_handler_install(
                $prompt,
                array('CliInput', 'readline_callback'.($history ? '_history' : ''))
                );
            readline_completion_function('usage_autocomplete');
            while (!CliInput::hasCaptured())
            {
                $read = array(STDIN);
                $write = null;
                $except = null;

                $change = stream_select($read, $write, $except, $sec_timeout, $milli_timeout);

                if ($change != 0)
                    readline_callback_read_char();
            }
        }

        // grab the input
        $input = CliInput::getCaptured();

        return $input;
    }

    public static function prompt_input($msg = null)
    {
        return self::prompt($msg, 60, 8192, false);
    }

    public static function prompt_confirm($msg)
    {
        $msg .= "\nConfirm [y/n]> ";
        return (strtolower(self::prompt($msg, 60, 1, false)) == 'y' ? true : false);
    }

    /**
     * Prompt the user to select from an list of options provided as an associative array.
     * 
     * @param arrat     a 1 dimensional, associative array to be printed as a list of options
     */
    public static function prompt_choice($msg, $choices)
    {
        $msg .= "\nChoice> ";

        // construct the tabular output
        $tbl = new Console_Table(
            CONSOLE_TABLE_ALIGN_LEFT,
            null,
            1
            );

        // add exit option
        $tbl->addRow(array('enter) ', 'skip / exit'));

        $maxlen = 0;
        foreach ($choices as $k => $v)
        {
            $maxlen = max(strlen($k), $maxlen);
            $tbl->addRow(array($k.') ', $v));
        }

        print $tbl->getTable();
        do
        {
            $input = self::prompt($msg, 60, $maxlen, false);
            if (strlen($input) == 0)
            {
                return false;
            }
            elseif (!array_key_exists($input, $choices))
            {
                print "[II] Invalid choice, try again.\n";
            }
            else
                break;
        }
        while (true);

        return $input;
    }

    public static function readline_callback_history($input)
    {
        self::readline_cb($input, true);
    }

    public static function readline_callback($input)
    {
        self::readline_cb($input, false);
    }

    protected static function readline_cb($input, $history)
    {
        if (!empty($input) && $history) readline_add_history($input);
        readline_callback_handler_remove();
        self::$last_captured = $input;
        self::$captured = true;
    }

    protected static function hasCaptured()
    {
        return self::$captured;
    }

    protected static function getCaptured()
    {
        $r = self::$last_captured;
        self::$last_captured = null;
        self::$captured = false;
        return $r;
    }

    public static function init()
    {
        // @todo - read history file from Conf::get('history_file')
    }

    public static function shutdown()
    {
        // @todo - write readline history to Conf::get('history_file') and
        // maintain history length no greater than Conf::get('history_size')
    }
}
