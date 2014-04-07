<?php
/**
 * ResqueRepeater - managing recurring/repeating jobs in resque
 *
 * Based on the php-resque-scheduler library by Chris Boulton
 *
 * @package		ResqueRepeater
 * @author		API Team <api@commonledger.com>
 * @copyright	(c) 2014 Common Ledger
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class ResqueRepeater
{
	const VERSION = "0.1";
    const SET_KEY = 'repeat';

    const ZPOP_SCRIPT = 'local job = redis.call("zrangebyscore", KEYS[1], ARGV[1], ARGV[1], "LIMIT", 0, 1); redis.call("zrem", KEYS[1], job[1]); return job[1]';

	/**
	 * Enqueue a job in a given number of seconds from now.
	 *
	 * Identical to Resque::enqueue, however the first argument is the number
	 * of seconds before the job should be executed.
	 *
	 * @param int $in Number of seconds from now when the job should be executed.
	 * @param string $queue The name of the queue to place the job in.
	 * @param string $class The name of the class that contains the code to execute the job.
	 * @param array $args Any optional arguments that should be passed when the job is executed.
	 */
	public static function enqueueIn($in, $queue, $class, array $args = array())
	{
		self::enqueueAt(time() + $in, $queue, $class, $args);
	}

	/**
	 * Enqueue a job for execution at a given timestamp.
	 *
	 * Identical to Resque::enqueue, however the first argument is a timestamp
	 * (either UNIX timestamp in integer format or an instance of the DateTime
	 * class in PHP).
	 *
	 * @param DateTime|int $at Instance of PHP DateTime object or int of UNIX timestamp.
	 * @param string $queue The name of the queue to place the job in.
	 * @param string $class The name of the class that contains the code to execute the job.
	 * @param array $args Any optional arguments that should be passed when the job is executed.
	 */
	public static function enqueueAt($at, $queue, $class, $args = array())
	{
		self::validateJob($class, $queue);

		$job = self::jobToHash($queue, $class, $args);
		self::delayedPush($at, $job);
		
		Resque_Event::trigger('afterRepeatSchedule', array(
			'at'    => $at,
			'queue' => $queue,
			'class' => $class,
			'args'  => $args,
		));
	}

	/**
	 * Directly append an item to the delayed queue schedule.
	 *
	 * @param DateTime|int $timestamp Timestamp job is scheduled to be run at.
	 * @param array $item Hash of item to be pushed to schedule.
	 */
	public static function delayedPush($timestamp, $item)
	{
		$timestamp = self::getTimestamp($timestamp);
		$redis = Resque::redis();
		$redis->zadd(self::SET_KEY, $timestamp, json_encode($item));
	}

	/**
	 * Get the total number of jobs in the delayed schedule.
	 *
	 * @return int Number of scheduled jobs.
	 */
	public static function getDelayedQueueScheduleSize()
	{
		return (int)Resque::redis()->zcard(self::SET_KEY);
	}

	/**
	 * Get the number of jobs for a given timestamp in the delayed schedule.
	 *
	 * @param DateTime|int $timestamp Timestamp
	 * @return int Number of scheduled jobs.
	 */
	public static function getDelayedTimestampSize($timestamp)
	{
		$timestamp = self::toTimestamp($timestamp);
		return Resque::redis()->zcount(self::SET_KEY, $timestamp, $timestamp);
	}

    /**
     * Remove a delayed job from the queue
     *
     * note: you must specify exactly the same
     * queue, class and arguments that you used when you added
     * to the delayed queue
     *
     * also, this is an expensive operation because all delayed keys have tobe
     * searched
     *
     * @param $queue
     * @param $class
     * @param $args
     * @return int number of jobs that were removed
     */
    public static function removeDelayed($queue, $class, $args)
    {
        $item=json_encode(self::jobToHash($queue, $class, $args));
        $redis=Resque::redis();

        $destroyed = $redis->zrem(self::SET_KEY, $item);
        return $destroyed;
    }

	/**
	 * Generate hash of all job properties to be saved in the scheduled queue.
	 *
	 * @param string $queue Name of the queue the job will be placed on.
	 * @param string $class Name of the job class.
	 * @param array $args Array of job arguments.
	 */

	private static function jobToHash($queue, $class, $args)
	{
		return array(
			'class' => $class,
			'args'  => array($args),
			'queue' => $queue,
		);
	}

	/**
	 * Convert a timestamp in some format in to a unix timestamp as an integer.
	 *
	 * @param DateTime|int $timestamp Instance of DateTime or UNIX timestamp.
	 * @return int Timestamp
	 * @throws ResqueScheduler_InvalidTimestampException
	 */
	private static function getTimestamp($timestamp)
	{
		if ($timestamp instanceof DateTime) {
			$timestamp = $timestamp->getTimestamp();
		}
		
		if ((int)$timestamp != $timestamp) {
			throw new ResqueScheduler_InvalidTimestampException(
				'The supplied timestamp value could not be converted to an integer.'
			);
		}

		return (int)$timestamp;
	}

	/**
	 * Find the first timestamp in the delayed schedule before/including the timestamp.
	 *
	 * Will find and return the first timestamp upto and including the given
	 * timestamp. This is the heart of the ResqueScheduler that will make sure
	 * that any jobs scheduled for the past when the worker wasn't running are
	 * also queued up.
	 *
	 * @param DateTime|int $timestamp Instance of DateTime or UNIX timestamp.
	 *                                Defaults to now.
	 * @return int|false UNIX timestamp, or false if nothing to run.
	 */
	public static function nextDelayedTimestamp($at = null)
	{
		if ($at === null) {
			$at = time();
		}
		else {
			$at = self::getTimestamp($at);
		}
	
		$items = Resque::redis()->zrangebyscore(self::SET_KEY, '-inf', $at, array(array('withscores' => true, 'limit' => array(0, 1))));
		if (!empty($items)) {
			return array_pop($items);
		}
		
		return false;
	}	
	
	/**
	 * Pop a job off the delayed queue for a given timestamp.
	 *
	 * @param DateTime|int $timestamp Instance of DateTime or UNIX timestamp.
	 * @return array Matching job at timestamp.
	 */
	public static function nextItemForTimestamp($timestamp)
	{
		$timestamp = self::getTimestamp($timestamp);
        $redis = Resque::redis();

        $key = Resque_Redis::getPrefix() . self::SET_KEY;
        $item = $redis->eval(self::ZPOP_SCRIPT, array(array($key, $timestamp)), 1);

        if(!empty($item)){
            return json_decode($item, true);
        }
		
		return false;
	}

	/**
	 * Ensure that supplied job class/queue is valid.
	 *
	 * @param string $class Name of job class.
	 * @param string $queue Name of queue.
	 * @throws Resque_Exception
	 */
	private static function validateJob($class, $queue)
	{
		if (empty($class)) {
			throw new Resque_Exception('Jobs must be given a class.');
		}
		else if (empty($queue)) {
			throw new Resque_Exception('Jobs must be put in a queue.');
		}
		
		return true;
	}
}
