<?php
/**
 * Exception thrown whenever an invalid timestamp has been passed to a job.
 * ResqueRepeater - managing recurring/repeating jobs in resque
 *
 * Based on the php-resque-scheduler library by Chris Boulton
 *
 * @package		ResqueRepeater
 * @author		API Team <api@commonledger.com>
 * @copyright	(c) 2014 Common Ledger
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class ResqueRepeater_InvalidTimestampException extends Resque_Exception
{

}