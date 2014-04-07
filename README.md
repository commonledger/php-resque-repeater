php-resque-repeater: PHP Resque Scheduler
==========================================

php-resque-repeater is a fork of the php-resque-scheduler library, designed for managing
repeating jobs that are run then enqueued again at a later time using Resque.

The major difference between this library and php-resque-scheduler is the repeating
jobs are stored in a sorted set, which ensures that each unique job is only scheduled
once. Any subsequent enqueues will overwrite the timestamp of the previous one. Basically,
this enforces that each unique job type (across queue, class and args) can only be queued
once.

The public api for php-resque-scheduler is similar to php-resque-scheduler. Further documentation
on usage is pending.

