<?php
/******************************************************************************
*
*  File: read_history.inc
*  Author:  Ken Brookman
*  Created: 10/20/02
*  Notes:  Made for phpBB 2.0.1 using mySQL v4.0 on Free BSD
*  Desc:  This will include some of the functions necessary to add smart post
*         tracking to phpBB. 
*
******************************************************************************/


// Function Mark all as read
// Desc:  This will delete all the entries for the current user in the
//        read_hist table meaning they have no new posts to read
//
function smart_mark_all_read()
{
   // Declare Globals
   //
   global $board_config, $lang, $db, $phpbb_root_path, $phpEx;
   global $userdata, $user_ip;

   // check for login
   //
   if ($userdata['session_logged_in'])
   {
      // Delete all this users posts that are unread
      //
      $sql = "DELETE FROM phpbb_read_history WHERE user_id = " 
             . $userdata['user_id'];

      // Run query & check for DB error
      //
      if ( !$db->sql_query($sql) )
      {
         message_die(GENERAL_ERROR, 'Error in marking all as read', '', 
                     __LINE__, __FILE__, $sql);
      }
   }
} // end smart_mark_all_read


// Function Submit a New Topic/Reply
// Desc: This will add an entry to everyone who can access this thread
//       meaning they need to read the topic (it is new to them)
//
function smart_submit(&$forum_id, &$topic_id, &$current_time)
{
   // Declare Globals
   //
   global $board_config, $lang, $db, $phpbb_root_path, $phpEx;
   global $userdata, $user_ip;

   // First Query:
   // Find out if the forum is a restricted access forum:
   // Notes: Here is the Key for the auth codes:
   // ADMIN = 5
   // MOD = 3
   // PRIVATE = 2
   // REG = 1
   // ALL = 0
   //
   // SELECT forum_id
   // FROM phpbb_forums
   // WHERE forum_id = $this_forum AND
   // auth_read > 1
   //
   // The result will either be empty (not restricted forum) or 
   // !empty (restricted forum).
   // 10/25/02 - We need to check for both read and view access to make
   // sure we aren't adding to people's new list if they can't even see
   // the forum.  This was brough about when we moved the ITEC News forum
   // to a "You CAN read it but CANNOT view it scenario.
   //
   $sql = "SELECT forum_id, auth_read, auth_view "
          . "FROM phpbb_forums WHERE "
          . "forum_id = $forum_id AND "
          . "(auth_read > 1 OR auth_view > 1)";

   // Run query & check for DB error
   //
   if ( !($result = $db->sql_query($sql)) )
   {
      message_die(GENERAL_ERROR, 'Error in submit checking forum access', '',
                  __LINE__, __FILE__, $sql);
   }

   // check to see if the forum is restricted (!empty)
   //
   if ( $row = $db->sql_fetchrow($result) )
   {
      //  If the result is !empty than do this query to insert for all the
      //  user_ids that have access to the forum
      //  Note that the Anonymous user (user_id = 1) is
      //  excluded because it is inactive (user_active = 0)
      //
      //  10/25/02 added the OR check for aa.auth_view = 1 because of the
      //  ITEC News forum where people can read it but cannot view it.
      //  This query will pick add an entry to anyone with special permissions
      //  in the auth_access table for read or view.
      //  There is a known issue now with giving people special permissions
      //  to just see a group that they cannot read yet.  This case will add
      //  An entry into their new list though they still cannot read it.
      //  I am hoping this never happens (giving a group special permissions
      //  to just view a forum they still cannot read)
      //
      //  11/09/02 fixed the problem by adding checks for 
      //  view/read/and read + view access levels.
      //
      $sql = "INSERT DELAYED IGNORE INTO phpbb_read_history "
             . "(user_id, forum_id, topic_id, post_time) "
             . "SELECT DISTINCT ug.user_id, $forum_id, $topic_id, "
             . "$current_time "
             . "FROM phpbb_user_group ug, phpbb_auth_access aa, phpbb_users us "
             . "WHERE aa.forum_id = $forum_id AND "
             . "us.user_active = 1 AND "
             . "ug.user_pending = 0 AND "
             . "ug.user_id != " . $userdata['user_id'] . " AND "
             . "aa.group_id = ug.group_id AND "
             . "ug.user_id = us.user_id AND ";
   
      if ($row['auth_view'] > 0)
      {
         if ($row['auth_read'] > 0)
         {
            // This case is for both read and view set to private more higher
            // restriction.   
            $sql .= "((aa.auth_read != 0 AND aa.auth_view != 0) OR "
                    . "aa.auth_mod = 1)";
         }
         else
         {
            // This case is for read set to private more higher but view was not
            $sql .= "(aa.auth_view != 0 OR  aa.auth_mod = 1)";
         }
      }
      else
      {
         // This case is for view set to private more higher but read was not
         $sql .= "(aa.auth_read != 0 OR  aa.auth_mod = 1)";
      }

      // Run query & check for DB error
      //
      if ( !$db->sql_query($sql) )
      {
         message_die(GENERAL_ERROR, 'Error in submit of restricted forum', '',
                     __LINE__, __FILE__, $sql);
      }
   }
   else
   {
      // The forum was not restricted
      //
      //  Note that the Anonymous user (user_id = -1) is
      //  excluded because it is inactive (user_active = 0)
      //
      $sql = "INSERT DELAYED INTO phpbb_read_history "
             . "(user_id, forum_id, topic_id, post_time) "
             . "SELECT DISTINCT user_id, $forum_id, $topic_id, $current_time "
             . "FROM phpbb_users "
             . "WHERE user_id != " . $userdata['user_id'] . " AND "
             . "user_active = 1 ";

      // Run query & check for DB error
      //
      if ( !$db->sql_query($sql) )
      {
         message_die(GENERAL_ERROR, 'Error in submit of UNrestricted forum', '',
                     __LINE__, __FILE__, $sql);
      }
   }
} // end smart_submit




// Function Read a Topic
// Desc: This will remove the thread from the user's (new) unread list
//
function smart_read_topic( &$topic_id )
{
   // Declare Globals
   //
   global $board_config, $lang, $db, $phpbb_root_path, $phpEx;
   global $userdata, $user_ip;

   // Check for login - if not loggin in don't need to remove anything
   //
   if ($userdata['session_logged_in'])
   {
      // create remove query
      // DELETE FROM phpbb_read_history
      // WHERE user_id = $user_id (from userdata)  AND
      // topic_id = $topic_id
      //
      $sql = "DELETE FROM phpbb_read_history "
             . "WHERE user_id = " . $userdata['user_id'] . " AND "
             . "topic_id = $topic_id";

      // Run query & check for DB error
      //
      if ( !$db->sql_query($sql) )
      {
         message_die(GENERAL_ERROR, 'Error in submit of UNrestricted forum', '',
                     __LINE__, __FILE__, $sql);
      }
   }
} // end smart_read_topic




// Function Check to see if a topic is new
// Desc: This will return a true if the topic is new to the user and false
//       if it isn't
//
function smart_is_new_topic( &$topic_id )
{
   // Declare Globals
   //
   global $board_config, $lang, $db, $phpbb_root_path, $phpEx;
   global $userdata, $user_ip;

   // Check for login - if not logged in don't need to check
   //
   if ($userdata['session_logged_in'])
   {
      //  check to see if there is an entry in the read history table
      //  with this user id and topic id 
      //
      $sql = "SELECT topic_id "
             . "FROM phpbb_read_history "
             . "WHERE topic_id = $topic_id AND "
             . "user_id = " . $userdata['user_id']; 

      // Run query & check for DB error
      //
      if ( !($result = $db->sql_query($sql)) )
      {
         message_die(GENERAL_ERROR, 'Error in submit checking forum access', '',
                     __LINE__, __FILE__, $sql);
      }

      // if we have an entry the topic was new to this user
      //
      if ( $row = $db->sql_fetchrow($result) )
      {
         return true;
      }
   }

   return false;
} // end smart_is_new_topic





// Function Check to see if a topic is newer than another topic time
// Desc: This is a bit different from the function above in the fact that
//       it checks a post time against the post time in the read_history table
//       It is used the viewtopic file when deciding to put a new folder
//       icon or a regular folder icon when actually viewing the posts.
//       It should help users find exactly which posts are new to them
//
function smart_is_new_topic_post( &$topic_id, &$post_time )
{
   // Declare Globals
   //
   global $board_config, $lang, $db, $phpbb_root_path, $phpEx;
   global $userdata, $user_ip;

   // Check for login - if not logged in don't need to check
   //
   if ($userdata['session_logged_in'])
   {
      //  check to see if there is an entry in the read history table
      //  with this user id and topic id 
      //
      $sql = "SELECT topic_id, post_time "
             . "FROM phpbb_read_history "
             . "WHERE topic_id = $topic_id AND "
             . "user_id = " . $userdata['user_id']; 

      // Run query & check for DB error
      //
      if ( !($result = $db->sql_query($sql)) )
      {
         message_die(GENERAL_ERROR, 'Error in submit checking forum access', '',
                     __LINE__, __FILE__, $sql);
      }

      // if we have an entry the topic was new to this user
      //
      if ( $row = $db->sql_fetchrow($result) )
      {
         if ( $row['post_time'] <= $post_time ) 
         { 
            return true;
         }
         else
         {
            return false;
         }
      }
   }

   return false;
} // end smart_is_new_topic_post





// Function Check to see if a forum is new
// Desc: This will return a true if the forum is new to the user and false
//       if it isn't
//
function smart_is_new_forum( &$forum_id )
{
   // Declare Globals
   //
   global $board_config, $lang, $db, $phpbb_root_path, $phpEx;
   global $userdata, $user_ip;

   // Check for login - if not logged in don't need to check
   //
   if ($userdata['session_logged_in'])
   {
      //  check to see if there is an entry in the read history table
      //  with this user id and topic id 
      //
      $sql = "SELECT forum_id "
             . "FROM phpbb_read_history "
             . "WHERE forum_id = $forum_id AND "
             . "user_id = " . $userdata['user_id']; 

      // Run query & check for DB error
      //
      if ( !($result = $db->sql_query($sql)) )
      {
         message_die(GENERAL_ERROR, 'Error in submit checking forum access', '',
                     __LINE__, __FILE__, $sql);
      }

      // if we have an entry the topic was new to this user
      //
      if ( $row = $db->sql_fetchrow($result) )
      {
         return true;
      }
   }

   return false;
} // end smart_is_new_forum




// Function Mark all the topics in this forum as read
// Desc: This will mark all the topics in this forum as read by deleting
//       them from the read history table
//
function smart_mark_forum_read( &$forum_id )
{
   // Declare Globals
   //
   global $board_config, $lang, $db, $phpbb_root_path, $phpEx;
   global $userdata, $user_ip;

   // Check for login - if not logged in don't need to check
   //
   if ($userdata['session_logged_in'])
   {
      // Delete all this users posts from the read history for this
      // forum
      //
      $sql = "DELETE FROM phpbb_read_history "
             . "WHERE user_id = " . $userdata['user_id'] . " AND "
             . "forum_id = $forum_id";

      // Run query & check for DB error
      //
      if ( !$db->sql_query($sql) )
      {
         message_die(GENERAL_ERROR, 'Error in marking all as read', '', 
                     __LINE__, __FILE__, $sql);
      }
   }
}  // end smart_mark_forum_read



// Function Delete Topic
// Desc: This will delete all the topics with the given topic_id from the 
//       read history table.  It is currently used in the move topic (modcp)
//       code.
//
function smart_delete_topic( &$topic_id )
{
   // Declare Globals
   //
   global $board_config, $lang, $db, $phpbb_root_path, $phpEx;
   global $userdata, $user_ip;

   // create remove query
   // DELETE FROM phpbb_read_history
   // WHERE  topic_id = $topic_id
   //
   $sql = "DELETE FROM phpbb_read_history "
          . "WHERE topic_id = $topic_id";

   // Run query & check for DB error
   //
   if ( !$db->sql_query($sql) )
   {
      message_die(GENERAL_ERROR, 'Error in submit of UNrestricted forum', '',
                  __LINE__, __FILE__, $sql);
   }
} // end smart_delete_topic




// Function Prune Users' Read Histories
// Desc: This function will delete all the topics with read historys greater 
//       than the given time.  Happens for all users.  This is used in case
//       the read_history table become too large and needs to be pruned.
//
function smart_prune_read_history( &$days_to_prune )
{
   // Declare Globals
   //
   global $board_config, $lang, $db, $phpbb_root_path, $phpEx;
   global $userdata, $user_ip;

   // Get the current time to subtract from
   //
   $current_time = time();

   // Convert the days param into seconds
   // Subtract the number of days from the current day to get a epoch
   // date to prune before
   //
   $time_to_prune = $current_time - ($day_to_prune * 86400);
   
   // query to execute
   // DELETE FROM phpbb_read_history
   // WHERE  post_time < $time_to_prune
   //
   $sql = "DELETE FROM phpbb_read_history "
          . "WHERE post_time < $time_to_prune";

   // Run query & check for DB error
   //
   if ( !$db->sql_query($sql) )
   {
      message_die(GENERAL_ERROR, 'Error in pruning read history', '',
                  __LINE__, __FILE__, $sql);
   }
} // end smart_delete_topi
// end file
