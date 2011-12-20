<?php

# ============================================================================
# This is a script to retrieve information from a MySQL server for input to a
# Cacti graphing process.
#
# This program is copyright (c) 2007 Baron Schwartz. Feedback and improvements
# are welcome.
#
# THIS PROGRAM IS PROVIDED "AS IS" AND WITHOUT ANY EXPRESS OR IMPLIED
# WARRANTIES, INCLUDING, WITHOUT LIMITATION, THE IMPLIED WARRANTIES OF
# MERCHANTIBILITY AND FITNESS FOR A PARTICULAR PURPOSE.
#
# This program is free software; you can redistribute it and/or modify it under
# the terms of the GNU General Public License as published by the Free Software
# Foundation, version 2.
#
# You should have received a copy of the GNU General Public License along with
# this program; if not, write to the Free Software Foundation, Inc., 59 Temple
# Place, Suite 330, Boston, MA  02111-1307  USA.
# ============================================================================

# ============================================================================
# Define MySQL connection constants in config.php.  Arguments explicitly passed
# in from Cacti will override these.  However, if you leave them blank in Cacti
# and set them here, you can make life easier.  Instead of defining parameters
# here, you can define them in another file named the same as this file, with a
# .cnf extension.
# ============================================================================
$mysql_user = 'cactiuser';
$mysql_pass = 'cactiuser';

$heartbeat  = '';      # db.tbl in case you use mk-heartbeat from Maatkit.
$cache_dir  = '/tmp';  # If set, this uses caching to avoid multiple calls.
$poll_time  = 300;     # Adjust to match your polling interval.
$chk_options = array (
   'innodb' => true,    # Do you want to check InnoDB statistics?
## off - dsr - we cant use master/slave checks @ amazon RDS, and they
## really dont seem to do us a damned bit of good when we do have them
#   'master' => true,    # Do you want to check binary logging?
#   'slave'  => true,    # Do you want to check slave status?
   'master' => false,    # Do you want to check binary logging?
   'slave'  => false,    # Do you want to check slave status?
   'procs'  => true,    # Do you want to check SHOW PROCESSLIST?
);
$use_ss     = FALSE; # Whether to use the script server or not

# ============================================================================
# You should not need to change anything below this line.
# ============================================================================

# ============================================================================
# Include settings from an external config file (issue 39).
# ============================================================================
if ( file_exists(__FILE__ . '.cnf' ) ) {
   require(__FILE__ . '.cnf');
}

# ============================================================================
# TODO items, if anyone wants to improve this script:
# * Make sure that this can be called by the script server.
# * Calculate query cache fragmentation as a percentage, something like
#   $status['Qcache_frag_bytes']
#     = $status['Qcache_free_blocks'] / $status['Qcache_total_blocks']
#        * $status['query_cache_size'];
# * Calculate relay log position lag
# ============================================================================

# ============================================================================
# Define whether you want debugging behavior.
# ============================================================================
$debug = TRUE;
error_reporting($debug ? E_ALL : E_ERROR);

# Make this a happy little script even when there are errors.
$no_http_headers = true;
ini_set('implicit_flush', false); # No output, ever.
ob_start(); # Catch all output such as notices of undefined array indexes.
function error_handler($errno, $errstr, $errfile, $errline) {
   print("$errstr at $errfile line $errline\n");
}
# ============================================================================
# Set up the stuff we need to be called by the script server.
# ============================================================================
if ( $use_ss ) {
   if ( file_exists( dirname(__FILE__) . "/../include/global.php") ) {
      # See issue 5 for the reasoning behind this.
      include_once(dirname(__FILE__) . "/../include/global.php");
   }
   elseif ( file_exists( dirname(__FILE__) . "/../include/config.php" ) ) {
      # Some versions don't have global.php.
      include_once(dirname(__FILE__) . "/../include/config.php");
   }
}

# ============================================================================
# Make sure we can also be called as a script.
# ============================================================================
if (!isset($called_by_script_server)) {
   array_shift($_SERVER["argv"]); # Strip off ss_get_mysql_stats.php
   $options = parse_cmdline($_SERVER["argv"]);
   validate_options($options);
   $result = ss_get_mysql_stats($options);
   if ( !$debug ) {
      # Throw away the buffer, which ought to contain only errors.
      ob_end_clean();
   }
   else {
      ob_end_flush(); # In debugging mode, print out the errors.
   }

   # Split the result up and extract only the desired parts of it.
   $wanted = explode(',', $options['items']);
   $output = array();
   foreach ( explode(' ', $result) as $item ) {
      if ( in_array(substr($item, 0, 2), $wanted) ) {
         $output[] = $item;
      }
   }
   print(implode(' ', $output));
}

# ============================================================================
# Work around the lack of array_change_key_case in older PHP.
# ============================================================================
if ( !function_exists('array_change_key_case') ) {
   function array_change_key_case($arr) {
      $res = array();
      foreach ( $arr as $key => $val ) {
         $res[strtolower($key)] = $val;
      }
      return $res;
   }
}

# ============================================================================
# Validate that the command-line options are here and correct
# ============================================================================
function validate_options($options) {
   $opts = array('host', 'items', 'user', 'pass', 'heartbeat', 'nocache');
   # Required command-line options
   foreach ( array('host', 'items') as $option ) {
      if ( !isset($options[$option]) || !$options[$option] ) {
         usage("Required option --$option is missing");
      }
   }
   foreach ( $options as $key => $val ) {
      if ( !in_array($key, $opts) ) {
         usage("Unknown option --$key");
      }
   }
}

# ============================================================================
# Print out a brief usage summary
# ============================================================================
function usage($message) {
   global $mysql_user, $mysql_pass, $heartbeat;

   $usage = <<<EOF
$message
Usage: php ss_get_mysql_stats.php --host <host> --items <item,...> [OPTION]

   --host      Hostname to connect to; use host:port syntax to specify a port
               Use :/path/to/socket if you want to connect via a UNIX socket
   --items     Comma-separated list of the items whose data you want
   --user      MySQL username; defaults to $mysql_user if not given
   --pass      MySQL password; defaults to $mysql_pass if not given
   --heartbeat MySQL heartbeat table; defaults to '$heartbeat' (see mk-heartbeat)
   --nocache   Do not cache results in a file

EOF;
   die($usage);
}

# ============================================================================
# Parse command-line arguments, in the format --arg value --arg value, and
# return them as an array ( arg => value )
# ============================================================================
function parse_cmdline( $args ) {
   $result = array();
   $cur_arg = '';
   foreach ($args as $val) {
      if ( strpos($val, '--') === 0 ) {
         if ( strpos($val, '--no') === 0 ) {
            # It's an option without an argument, but it's a --nosomething so
            # it's OK.
            $result[substr($val, 2)] = 1;
            $cur_arg = '';
         }
         elseif ( $cur_arg ) { # Maybe the last --arg was an option with no arg
            if ( $cur_arg == '--user' || $cur_arg == '--pass' ) {
               # Special case because Cacti will pass --user without an arg
               $cur_arg = '';
            }
            else {
               die("Missing argument to $cur_arg\n");
            }
         }
         else {
            $cur_arg = $val;
         }
      }
      else {
         $result[substr($cur_arg, 2)] = $val;
         $cur_arg = '';
      }
   }
   if ( $cur_arg && ($cur_arg != '--user' && $cur_arg != '--pass') ) {
      die("Missing argument to $cur_arg\n");
   }
   return $result;
}

# ============================================================================
# This is the main function.  Some parameters are filled in from defaults at the
# top of this file.
# ============================================================================
function ss_get_mysql_stats( $options ) {
   # Process connection options and connect to MySQL.
   global $debug, $mysql_user, $mysql_pass, $heartbeat, $cache_dir, $poll_time,
          $chk_options;

   # Connect to MySQL.
   $user = isset($options['user']) ? $options['user'] : $mysql_user;
   $pass = isset($options['pass']) ? $options['pass'] : $mysql_pass;
   $heartbeat = isset($options['heartbeat']) ? $options['heartbeat'] : $heartbeat;
   $conn = @mysql_connect($options['host'], $user, $pass);
   if ( !$conn ) {
      die("Can't connect to MySQL: " . mysql_error());
   }

   $sanitized_host
       = str_replace(array(":", "/"), array("", "_"), $options['host']);
   $cache_file = "$cache_dir/$sanitized_host-mysql_cacti_stats.txt";

   # First, check the cache.
   $fp = null;
   if ( !isset($options['nocache']) ) {
      # This will block if someone else is accessing the file.
      $result = run_query(
         "SELECT GET_LOCK('cacti_monitoring', $poll_time) AS ok", $conn);
      $row = @mysql_fetch_assoc($result);
      if ( $row['ok'] ) { # Nobody else had the file locked.
         if ( file_exists($cache_file) && filesize($cache_file) > 0
            && filectime($cache_file) + ($poll_time/2) > time() )
         {
            # The file is fresh enough to use.
            $arr = file($cache_file);
            # The file ought to have some contents in it!  But just in case it
            # doesn't... (see issue #6).
            if ( count($arr) ) {
               run_query("SELECT RELEASE_LOCK('cacti_monitoring')", $conn);
               return $arr[0];
            }
            else {
               if ( $debug ) {
                  trigger_error("The function file($cache_file) returned nothing!\n");
               }
            }
         }
      }
      if ( !$fp = fopen($cache_file, 'w+') ) {
         die("Cannot open file '$cache_file'");
      }
   }

   # Set up variables.
   $status = array( # Holds the result of SHOW STATUS, SHOW INNODB STATUS, etc
      # Define some indexes so they don't cause errors with += operations.
      'transactions'          => null,
      'relay_log_space'       => null,
      'binary_log_space'      => null,
      'current_transactions'  => null,
      'locked_transactions'   => null,
      'active_transactions'   => null,
      'innodb_locked_tables'  => null,
      'innodb_lock_structs'   => null,
      # Values for the 'state' column from SHOW PROCESSLIST (converted to
      # lowercase, with spaces replaced by underscores)
      'State_closing_tables'       => null,
      'State_copying_to_tmp_table' => null,
      'State_end'                  => null,
      'State_freeing_items'        => null,
      'State_init'                 => null,
      'State_locked'               => null,
      'State_login'                => null,
      'State_preparing'            => null,
      'State_reading_from_net'     => null,
      'State_sending_data'         => null,
      'State_sorting_result'       => null,
      'State_statistics'           => null,
      'State_updating'             => null,
      'State_writing_to_net'       => null,
      'State_none'                 => null,
      'State_other'                => null, # Everything not listed above
   );

   # Get SHOW STATUS and convert the name-value array into a simple
   # associative array.
   $result = run_query("SHOW /*!50002 GLOBAL */ STATUS", $conn);
   while ($row = @mysql_fetch_row($result)) {
      $status[$row[0]] = $row[1];
   }

   # Get SHOW VARIABLES and convert the name-value array into a simple
   # associative array.
   $result = run_query("SHOW VARIABLES", $conn);
   while ($row = @mysql_fetch_row($result)) {
      $status[$row[0]] = $row[1];
   }

   # Get SHOW SLAVE STATUS.
   if ( $chk_options['slave'] ) {
      $result = run_query("SHOW SLAVE STATUS", $conn);
      while ($row = @mysql_fetch_assoc($result)) {
         # Must lowercase keys because different versions have different
         # lettercase.
         $row = array_change_key_case($row, CASE_LOWER);
         $status['relay_log_space']  = $row['relay_log_space'];
         $status['slave_lag']        = $row['seconds_behind_master'];

         # Check replication heartbeat, if present.
         if ( $heartbeat ) {
            $result = run_query(
               "SELECT GREATEST(0, UNIX_TIMESTAMP() - UNIX_TIMESTAMP(ts) - 1)"
               . "FROM $heartbeat WHERE id = 1", $conn);
            $row2 = @mysql_fetch_row($result);
            $status['slave_lag'] = $row2[0];
         }

         # Scale slave_running and slave_stopped relative to the slave lag.
         $status['slave_running'] = ($row['slave_sql_running'] == 'Yes')
            ? $status['slave_lag'] : 0;
         $status['slave_stopped'] = ($row['slave_sql_running'] == 'Yes')
            ? 0 : $status['slave_lag'];
      }
   }

   # Get info on master logs. TODO: is there a way to do this without querying
   # mysql again?
   $binlogs = array(0);
   if ( $chk_options['master'] && $status['log_bin'] == 'ON' ) { # See issue #8
      $result = run_query("SHOW MASTER LOGS", $conn);
      while ($row = @mysql_fetch_assoc($result)) {
         $row = array_change_key_case($row, CASE_LOWER);
         # Older versions of MySQL may not have the File_size column in the
         # results of the command.  Zero-size files indicate the user is
         # deleting binlogs manually from disk (bad user! bad!) but we should
         # not croak with a thread-stack error just because of the bad user.
         if ( array_key_exists('file_size', $row) && $row['file_size'] > 0 ) {
            $binlogs[] = $row['file_size'];
         }
         else {
            break;
         }
      }
   }

   # Get SHOW PROCESSLIST and aggregate it.
   if ( $chk_options['procs'] ) {
      $result = run_query('SHOW PROCESSLIST', $conn);
      while ($row = @mysql_fetch_assoc($result)) {
         $state = $row['State'];
         if ( is_null($state) ) {
            $state = 'NULL';
         }
         if ( $state == '' ) {
            $state = 'none';
         }
         $state = str_replace(' ', '_', strtolower($state));
         if ( array_key_exists("State_$state", $status) ) {
            increment($status, "State_$state", 1);
         }
         else {
            increment($status, "State_other", 1);
         }
      }
   }

   # Get SHOW INNODB STATUS and extract the desired metrics from it. See issue
   # #8.
   $innodb_txn      = false;
   $innodb_complete = false;
   if ( $chk_options['innodb'] && $status['have_innodb'] == 'YES' ) {
      $result        = run_query("SHOW /*!50000 ENGINE*/ INNODB STATUS", $conn);
      $innodb_array  = @mysql_fetch_assoc($result);
      $flushed_to    = false;
      $innodb_lsn    = false;
      $innodb_prg    = false;
      $is_plugin     = false;
      $spin_waits    = array();
      $spin_rounds   = array();
      $os_waits      = array();
      foreach ( explode("\n", $innodb_array['Status']) as $line ) {
         $row = explode(' ', $line);

         # SEMAPHORES
         if (strpos($line, 'Mutex spin waits') !== FALSE ) {
            $spin_waits[]  = tonum($row[3]);
            $spin_rounds[] = tonum($row[5]);
            $os_waits[]    = tonum($row[8]);
         }
         elseif (strpos($line, 'RW-shared spins') !== FALSE ) {
            $spin_waits[] = tonum($row[2]);
            $spin_waits[] = tonum($row[8]);
            $os_waits[]   = tonum($row[5]);
            $os_waits[]   = tonum($row[11]);
         }

         # TRANSACTIONS
         elseif ( strpos($line, 'Trx id counter') !== FALSE ) {
            # The beginning of the TRANSACTIONS section: start counting
            # transactions
            $innodb_txn = isset($row[4]) ? array($row[3], $row[4]) : tonum($row[3]);
         }
         elseif (strpos($line, 'Purge done for trx') !== FALSE ) {
            # PHP can't do big math, so I send it to MySQL.
            $innodb_prg = $row[7]=='undo' ? tonum($row[6]) : array($row[6], $row[7]);
         }
         elseif (strpos($line, 'History list length') !== FALSE ) {
            $status['history_list'] = tonum($row[3]);
         }
         elseif ( $innodb_txn && strpos($line, '---TRANSACTION') !== FALSE ) {
            increment($status, 'current_transactions', 1);
            if ( strpos($line, 'ACTIVE') !== FALSE  ) {
               increment($status, 'active_transactions', 1);
            }
         }
         elseif ( $innodb_txn && strpos($line, 'LOCK WAIT') !== FALSE  ) {
            increment($status, 'locked_transactions', 1);
         }
         elseif ( strpos($line, 'read views open inside') !== FALSE ) {
            $status['read_views'] = tonum($row[0]);
         }
         elseif ( strpos($line, 'mysql tables in use') !== FALSE  ) {
            increment($status, 'innodb_locked_tables', tonum($row[6]));
         }
         elseif ( strpos($line, 'lock struct(s) !== FALSE ') ) {
            increment($status, 'innodb_lock_structs', tonum($row[0]));
         }

         # FILE I/O
         elseif (strpos($line, 'OS file reads') !== FALSE ) {
            $status['file_reads']  = tonum($row[0]);
            $status['file_writes'] = tonum($row[4]);
            $status['file_fsyncs'] = tonum($row[8]);
         }
         elseif (strpos($line, 'Pending normal aio') !== FALSE ) {
            $status['pending_normal_aio_reads']  = tonum($row[4]);
            $status['pending_normal_aio_writes'] = tonum($row[7]);
         }
         elseif (strpos($line, 'ibuf aio reads') !== FALSE ) {
            $status['pending_ibuf_aio_reads'] = tonum($row[4]);
            $status['pending_aio_log_ios']    = tonum($row[7]);
            $status['pending_aio_sync_ios']   = tonum($row[10]);
         }
         elseif (strpos($line, 'Pending flushes (fsync) !== FALSE ')) {
            $status['pending_log_flushes']      = tonum($row[4]);
            $status['pending_buf_pool_flushes'] = tonum($row[7]);
         }

         # INSERT BUFFER AND ADAPTIVE HASH INDEX
         elseif (strpos($line, 'merged recs') !== FALSE ) {
            $status['ibuf_inserts'] = tonum($row[0]);
            $status['ibuf_merged']  = tonum($row[2]);
            $status['ibuf_merges']  = tonum($row[5]);
         }

         # LOG
         elseif (strpos($line, "log i/o's done") !== FALSE ) {
            $status['log_writes'] = tonum($row[0]);
         }
         elseif (strpos($line, "pending log writes") !== FALSE ) {
            $status['pending_log_writes']  = tonum($row[0]);
            $status['pending_chkp_writes'] = tonum($row[4]);
         }
         elseif (strpos($line, "Log sequence number") !== FALSE ) {
            # 5.1 plugin displays differently (issue 52)
            $innodb_lsn = isset($row[4]) ? array($row[3], $row[4]) : tonum($row[3]);
         }
         elseif (strpos($line, "Log flushed up to") !== FALSE ) {
            # Since PHP can't handle 64-bit numbers, we'll ask MySQL to do it for
            # us instead.  And we get it to cast them to strings, too.
            $flushed_to = isset($row[7]) ? array($row[6], $row[7]) : tonum($row[6]);
         }

         # BUFFER POOL AND MEMORY
         elseif (strpos($line, "Buffer pool size ") !== FALSE ) {
            # 5.1 plugin displays differently (issue 52)
            $status['pool_size'] = isset($row[10]) ? tonum($row[10]) : tonum($row[5]);
         }
         elseif (strpos($line, "Buffer pool size, bytes") !== FALSE ) {
            $is_plugin = true;
         }
         elseif (strpos($line, "Free buffers") !== FALSE ) {
             $status['free_pages'] = tonum($row[8]);
         }
         elseif (strpos($line, "Database pages") !== FALSE ) {
             $status['database_pages'] = tonum($row[6]);
         }
         elseif (strpos($line, "Modified db pages") !== FALSE ) {
             $status['modified_pages'] = tonum($row[4]);
         }
         elseif (strpos($line, "Pages read") !== FALSE  ) {
             $status['pages_read']    = tonum($row[2]);
             $status['pages_created'] = tonum($row[4]);
             $status['pages_written'] = tonum($row[6]);
         }

         # ROW OPERATIONS
         elseif (strpos($line, 'Number of rows inserted') !== FALSE ) {
            $status['rows_inserted'] = tonum($row[4]);
            $status['rows_updated']  = tonum($row[6]);
            $status['rows_deleted']  = tonum($row[8]);
            $status['rows_read']     = tonum($row[10]);
         }
         elseif (strpos($line, "queries inside InnoDB") !== FALSE ) {
             $status['queries_inside'] = tonum($row[0]);
             $status['queries_queued']  = tonum($row[4]);
         }
      }
      $innodb_complete
         = strpos($innodb_array['Status'], 'END OF INNODB MONITOR OUTPUT');
   }

   if ( !$innodb_complete ) {
      # TODO: Fill in some values with stuff from SHOW STATUS.
   }

   # Derive some values from other values.

   # PHP sucks at bigint math, so we use MySQL to calculate things that are
   # too big for it.
   if ( $innodb_txn ) {
      if (!$is_plugin) {
         $txn = make_bigint_sql($innodb_txn[0], $innodb_txn[1]);
         $lsn = make_bigint_sql($innodb_lsn[0], $innodb_lsn[1]);
         $flu = make_bigint_sql($flushed_to[0], $flushed_to[1]);
         $prg = make_bigint_sql($innodb_prg[0], $innodb_prg[1]);
      }
      else {
         $txn = make_decimal_sql($innodb_txn);
         $lsn = make_decimal_sql($innodb_lsn);
         $flu = make_decimal_sql($flushed_to);
         $prg = make_decimal_sql($innodb_prg);
      }
      $sql = "SELECT CONCAT('', $txn) AS innodb_transactions, "
           . "CONCAT('', ($txn - $prg)) AS unpurged_txns, "
           . "CONCAT('', $lsn) AS log_bytes_written, "
           . "CONCAT('', $flu) AS log_bytes_flushed, "
           . "CONCAT('', ($lsn - $flu)) AS unflushed_log, "
           . "CONCAT('', " . implode('+', $spin_waits) . ") AS spin_waits, "
           . "CONCAT('', " . implode('+', $spin_rounds) . ") AS spin_rounds, "
           . "CONCAT('', " . implode('+', $os_waits) . ") AS os_waits";
      # echo("$sql\n");
      $result = run_query($sql, $conn);
      while ( $row = @mysql_fetch_assoc($result) ) {
         foreach ( $row as $key => $val ) {
            $status[$key] = $val;
         }
      }
      # TODO: I'm not sure what the deal is here; need to debug this.  But the
      # unflushed log bytes spikes a lot sometimes and it's impossible for it to
      # be more than the log buffer.
      $status['unflushed_log']
         = max($status['unflushed_log'], $status['innodb_log_buffer_size']);
   }
   if (count($binlogs)) {
      $status['binary_log_space'] = sprintf('%u', array_sum($binlogs));
   }

   # Define the variables to output.  I use shortened variable names so maybe
   # it'll all fit in 1024 bytes for Cactid and Spine's benefit.  This list must
   # come right after the word MAGIC_VARS_DEFINITIONS.  The Perl script parses
   # it and uses it as a Perl variable.
   # this is the list of --items !
   $keys = array(
       'Key_read_requests'          => 'a0',
       'Key_reads'                  => 'a1',
       'Key_write_requests'         => 'a2',
       'Key_writes'                 => 'a3',
       'history_list'               => 'a4',
       'innodb_transactions'        => 'a5',
       'read_views'                 => 'a6',
       'current_transactions'       => 'a7',
       'locked_transactions'        => 'a8',
       'active_transactions'        => 'a9',
       'pool_size'                  => 'aa',
       'free_pages'                 => 'ab',
       'database_pages'             => 'ac',
       'modified_pages'             => 'ad',
       'pages_read'                 => 'ae',
       'pages_created'              => 'af',
       'pages_written'              => 'ag',
       'file_fsyncs'                => 'ah',
       'file_reads'                 => 'ai',
       'file_writes'                => 'aj',
       'log_writes'                 => 'ak',
       'pending_aio_log_ios'        => 'al',
       'pending_aio_sync_ios'       => 'am',
       'pending_buf_pool_flushes'   => 'an',
       'pending_chkp_writes'        => 'ao',
       'pending_ibuf_aio_reads'     => 'ap',
       'pending_log_flushes'        => 'aq',
       'pending_log_writes'         => 'ar',
       'pending_normal_aio_reads'   => 'as',
       'pending_normal_aio_writes'  => 'at',
       'ibuf_inserts'               => 'au',
       'ibuf_merged'                => 'av',
       'ibuf_merges'                => 'aw',
       'spin_waits'                 => 'ax',
       'spin_rounds'                => 'ay',
       'os_waits'                   => 'az',
       'rows_inserted'              => 'b0',
       'rows_updated'               => 'b1',
       'rows_deleted'               => 'b2',
       'rows_read'                  => 'b3',
       'Table_locks_waited'         => 'b4',
       'Table_locks_immediate'      => 'b5',
       'Slow_queries'               => 'b6',
       'Open_files'                 => 'b7',
       'Open_tables'                => 'b8',
       'Opened_tables'              => 'b9',
       'innodb_open_files'          => 'ba',
       'open_files_limit'           => 'bb',
       'table_cache'                => 'bc',
       'Aborted_clients'            => 'bd',
       'Aborted_connects'           => 'be',
       'Max_used_connections'       => 'bf',
       'Slow_launch_threads'        => 'bg',
       'Threads_cached'             => 'bh',
       'Threads_connected'          => 'bi',
       'Threads_created'            => 'bj',
       'Threads_running'            => 'bk',
       'max_connections'            => 'bl',
       'thread_cache_size'          => 'bm',
       'Connections'                => 'bn',
       'slave_running'              => 'bo',
       'slave_stopped'              => 'bp',
       'Slave_retried_transactions' => 'bq',
       'slave_lag'                  => 'br',
       'Slave_open_temp_tables'     => 'bs',
       'Qcache_free_blocks'         => 'bt',
       'Qcache_free_memory'         => 'bu',
       'Qcache_hits'                => 'bv',
       'Qcache_inserts'             => 'bw',
       'Qcache_lowmem_prunes'       => 'bx',
       'Qcache_not_cached'          => 'by',
       'Qcache_queries_in_cache'    => 'bz',
       'Qcache_total_blocks'        => 'c0',
       'query_cache_size'           => 'c1',
       'Questions'                  => 'c2',
       'Com_update'                 => 'c3',
       'Com_insert'                 => 'c4',
       'Com_select'                 => 'c5',
       'Com_delete'                 => 'c6',
       'Com_replace'                => 'c7',
       'Com_load'                   => 'c8',
       'Com_update_multi'           => 'c9',
       'Com_insert_select'          => 'ca',
       'Com_delete_multi'           => 'cb',
       'Com_replace_select'         => 'cc',
       'Select_full_join'           => 'cd',
       'Select_full_range_join'     => 'ce',
       'Select_range'               => 'cf',
       'Select_range_check'         => 'cg',
       'Select_scan'                => 'ch',
       'Sort_merge_passes'          => 'ci',
       'Sort_range'                 => 'cj',
       'Sort_rows'                  => 'ck',
       'Sort_scan'                  => 'cl',
       'Created_tmp_tables'         => 'cm',
       'Created_tmp_disk_tables'    => 'cn',
       'Created_tmp_files'          => 'co',
       'Bytes_sent'                 => 'cp',
       'Bytes_received'             => 'cq',
       'innodb_log_buffer_size'     => 'cr',
       'unflushed_log'              => 'cs',
       'log_bytes_flushed'          => 'ct',
       'log_bytes_written'          => 'cu',
       'relay_log_space'            => 'cv',
       'binlog_cache_size'          => 'cw',
       'Binlog_cache_disk_use'      => 'cx',
       'Binlog_cache_use'           => 'cy',
       'binary_log_space'           => 'cz',
       'innodb_locked_tables'       => 'd0',
       'innodb_lock_structs'        => 'd1',
       'State_closing_tables'       => 'd2',
       'State_copying_to_tmp_table' => 'd3',
       'State_end'                  => 'd4',
       'State_freeing_items'        => 'd5',
       'State_init'                 => 'd6',
       'State_locked'               => 'd7',
       'State_login'                => 'd8',
       'State_preparing'            => 'd9',
       'State_reading_from_net'     => 'da',
       'State_sending_data'         => 'db',
       'State_sorting_result'       => 'dc',
       'State_statistics'           => 'dd',
       'State_updating'             => 'de',
       'State_writing_to_net'       => 'df',
       'State_none'                 => 'dg',
       'State_other'                => 'dh',
       'Handler_commit'             => 'di',
       'Handler_delete'             => 'dj',
       'Handler_discover'           => 'dk',
       'Handler_prepare'            => 'dl',
       'Handler_read_first'         => 'dm',
       'Handler_read_key'           => 'dn',
       'Handler_read_next'          => 'do',
       'Handler_read_prev'          => 'dp',
       'Handler_read_rnd'           => 'dq',
       'Handler_read_rnd_next'      => 'dr',
       'Handler_rollback'           => 'ds',
       'Handler_savepoint'          => 'dt',
       'Handler_savepoint_rollback' => 'du',
       'Handler_update'             => 'dv',
       'Handler_write'              => 'dw',
   );

   # Return the output.
   $output = array();
   foreach ($keys as $key => $short ) {
      # If the value isn't defined, return -1 which is lower than (most graphs')
      # minimum value of 0, so it'll be regarded as a missing value.
      $val      = isset($status[$key]) ? $status[$key] : -1;
      $output[] = "$short:$val";
   }
   $result = implode(' ', $output);
   if ( $fp ) {
      if ( fwrite($fp, $result) === FALSE ) {
         die("Cannot write to '$cache_file'");
      }
      fclose($fp);
      run_query("SELECT RELEASE_LOCK('cacti_monitoring')", $conn);
   }
   return $result;
}

# ============================================================================
# Returns SQL to create a bigint from two ulint
# ============================================================================
function make_bigint_sql ($hi, $lo) {
   $hi = $hi ? $hi : '0'; # Handle empty-string or whatnot
   $lo = $lo ? $lo : '0';
   return "(($hi << 32) + $lo)";
}

# ============================================================================
# Baseconvert from hexidecimal to decimal
# ============================================================================
function make_decimal_sql($str) {
   return "conv('$str', 16, 10)";
}

# ============================================================================
# Extracts the numbers from a string.  You can't reliably do this by casting to
# an int, because numbers that are bigger than PHP's int (varies by platform)
# will be truncated.  So this just handles them as a string instead.  Note that
# all bigint math is done by sending values in a query to MySQL!  :-)
# ============================================================================
function tonum ( $str ) {
   global $debug;
   preg_match('{(\d+)}', $str, $m); 
   if ( isset($m[1]) ) {
      return $m[1];
   }
   elseif ( $debug ) {
      print_r(debug_backtrace());
   }
   else {
      return 0;
   }
}

# ============================================================================
# Wrap mysql_query in error-handling
# ============================================================================
function run_query($sql, $conn) {
   global $debug;
   $result = @mysql_query($sql, $conn);
   if ( $debug ) {
      $error = @mysql_error($conn);
      if ( $error ) {
         die("Error executing '$sql': $error");
      }
   }
   return $result;
}

# ============================================================================
# Safely increments a value that might be null.
# ============================================================================
function increment(&$arr, $key, $howmuch) {
   if ( array_key_exists($key, $arr) && isset($arr[$key]) ) {
      $arr[$key] += $howmuch;
   }
   else {
      $arr[$key] = $howmuch;
   }
}

?>
