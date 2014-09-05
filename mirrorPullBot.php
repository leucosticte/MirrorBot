<?php
/**
 * MirrorPullBot
 * Version 1.0.2
 * https://www.mediawiki.org/wiki/Extension:MirrorTools
 * By Leucosticte < https://www.mediawiki.org/wiki/User:Leucosticte >
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

// Initialize database
require_once( 'mirrorInitializeDb.php' );
require_once( $config->botClassesPath . "/botclasses.php" );

$botOperations = new botOperations( $passwordConfig, $config, $db, getopt( 'q:r:s:') );
$botOperations->botLoop();

class botOperations {
      public $passes = 0; // Which part of the loop are we on?
      public $wiki;
      public $options;
      public $sleepMicroseconds;
      public $startingTimestamp;
      public $db;
      public $config;
      public $passwordConfig;

      function __construct( $passwordConfig, $config, $db, $options ) {
            $this->config = $config;
            $this->options = $options;
            $this->db = $db;
            $this->passwordConfig = $passwordConfig;
            $this->initialize();
      }

      function botLoop() {
            $optionThisTime = $this->options['q'];
            while ( $this->options['r'] != 'o' || !$this->passes ) {
                  $this->passes++;
                  if ( $this->options['q'] == 'rcrev' ) {
                        switch ( $optionThisTime ) {
                              case 'rc':
                                    $optionThisTime = 'rev';
                                    break;
                              case 'rev':
                                    $optionThisTime = 'movenullrev';
                                    break;
                              case 'movenullrev':
                                    $optionThisTime = 'pagerestorerevids';
                                    break;
                              case 'pagerestorerevids':
                                    $optionThisTime = 'rc';
                                    break;
                        }
                  }
                  switch ( $optionThisTime ) {
                        case 'rc':
                              $this->rc();
                              break;
                        case 'rev':
                              $this->rev();
                              break;
                        case 'movenullrev':
                              $this->movenullrev();
                              break;
                        case 'pagerestorerevids':
                              $this->pagerestorerevids();
                              break;
                  }
                  if ( $this->options['r'] != 'o' ) {
                        echo "Sleeping " . $this->sleepMicroseconds . " microseconds...";
                        usleep ( $this->sleepMicroseconds );
                        echo "done sleeping.\n";
                  }
            }
      }

      function rc() {
            $rcStart = '';
            $continueValue = '';
            $skip = false;
            $rcContinue = '';
            $rcContinueForQuery = '';
            $mbcId = 0;
            $mbcNumRows = 0;
            // Get starting timestamp, from the default if necessary
            if ( $this->passes == 1 ) {
                  if ( !$this->startingTimestamp ) {
                        $this->startingTimestamp = $this->config->defaultStart['rc'];
                  }
                  $rcStart = "&rcstart=" . $this->startingTimestamp;
                  $continueResult = $this->db->query( 'SELECT * FROM mb_cursor WHERE'
                        . " mbc_key='rccontinue'" );
                  if ( $continueResult ) {
                        $continueValueArr = $continueResult->fetch_assoc();
                        $continueValue = $continueValueArr['mbc_value'];
                        $mbcId = $continueValueArr['mbc_id'];
                        $mbcNumRows = $continueResult->num_rows;
                        // Y = Yes, skip. N = No, don't skip.
                        $skipVal = substr( $continueValue, 0, 1 );
                        if ( $skipVal == 'Y' ) {
                              $skip = true;
                        }
                        $continueValue = substr( $continueValue, 1,
                              strlen( $continueValue ) - 1 );
                        if ( $continueValue ) {
                              $rcContinueForQuery = "&rccontinue=$continueValue";
                        }
                  }
            }
            $ret = $this->wiki->query ( "?action=query&list=recentchanges"
                  . "$rcStart&rcdir=newer&rcprop=user|userid|comment|timestamp|"
                  . "patrolled|title|ids|sizes|redirect|loginfo|flags|sha1|tags&rclimit=" . $this->config->rcLimit
                  . "$rcContinueForQuery&format=php", true );
            if ( isset( $ret['query-continue']['recentchanges']['rccontinue'] ) ) {
                  $rcContinue = 'N' . $ret['query-continue']['recentchanges']['rccontinue'];
            }
            if ( !isset( $ret['query']['recentchanges'] ) ) {
                  echo( "API did not give the required query\n" );
                  var_dump( $ret );
                  return;
            }
            if ( !$ret['query']['recentchanges'] ) {
                  echo( "There were no recent changes results\n" );
                  return;
            }
            $events = $ret['query']['recentchanges'];
            $table = 'mb_queue';
            $dbFields = array_keys ( $this->config->fields['rc'] );
            $userRow = array_values ( $this->config->fields['rc'] );
            $undesirables = array ( '-', ':', 'T', 'Z' );
            $row = 'insert into ' . $table . ' ( ' . implode ( ', ', $dbFields ) . ' ) values ';
            $isFirstInEvent = true;
            $events = $ret['query']['recentchanges'];
            // For each event in that result set
            if ( $skip ) {
                  $exploded = explode( '|', $continueValue );
                  if ( $events[0]['rcid'] == $exploded['1'] ) {
                        array_shift( $events );
                  }
            }
            if ( !$events ) {
                  echo "No events\n";
                  return;
            }
            foreach ( $events as $thisLogevent ) {
                  $deleted = 0;
                  if ( !$isFirstInEvent ) {
                        $row .= ', ';
                  }
                  $isFirstInEvent = false;
                  $row .= '( ';
                  $isFirstInItem = true;
                  // Get rid of dashes, colons, Ts and Zs in timestamp
                  $thisLogevent['timestamp'] = str_replace ( $undesirables, '',
                        $thisLogevent['timestamp'] );
                  foreach( $this->config->namespacesToTruncate as $namespaceToTruncate ) {
                        if ( substr( $thisLogevent['title'], 0,
                              strlen( $namespaceToTruncate ) ) == $namespaceToTruncate ) {
                              $thisLogevent['title'] = substr( $thisLogevent['title'],
                                    strlen( $namespaceToTruncate ),
                                    strlen( $thisLogevent['title'] )
                                    - strlen( $namespaceToTruncate ) );
                              break;
                        }
                  }
                  if ( isset( $thisLogevent['type'] ) ) {
                        if ( $thisLogevent['type'] == 'edit'
                              || $thisLogevent['type'] == 'new' ) {
                              $thisLogevent['mbqaction'] = 'mirroredit';
                              $thisLogevent['mbqstatus'] = 'needsrev';
                        }
                  }
                  if ( isset( $thisLogevent['logaction'] ) ) {
                        if ( isset( $this->config->mirrorActions[$thisLogevent['logaction']] ) ) {
                              $thisLogevent['mbqstatus'] = 'readytopush'; // Default
                              if ( $thisLogevent['timestamp'] > $this->config->importCutoff ) {
                                    $thisLogevent['mbqaction'] =
                                          $this->config->mirrorActions[$thisLogevent['logaction']];
                              } else {
                                    $thisLogevent['mbqaction'] = 'mirrorlogentry';
                              }
                              // TODO: What about the different kinds of moves?
                              if ( $thisLogevent['logtype'] == 'move' ) {
                                    $thisLogevent['mbqstatus'] = 'needsmovenullrev';
                              }
                              if ( $thisLogevent['logaction'] == 'restore' ) {
                                    $thisLogevent['mbqstatus'] = 'needsrevids';
                              }
                        }
                        if ( isset( $thisLogevent['actionhidden'] ) ) {
                              $deleted++;
                        }
                        if ( isset( $thisLogevent['commenthidden'] ) ) {
                              $deleted += 2;
                        }
                        if ( isset( $thisLogevent['userhidden'] ) ) {
                              $deleted += 4;
                        }
                        $thisLogevent['mbqdeleted'] = $deleted;
                  }
                  if ( isset( $thisLogevent['move'] ) ) {
                        if ( isset( $thisLogevent['redirect'] ) ) {
                              $noredirect = '0';
                        } else {
                              $noredirect = '1';
                        }
                        $thisLogevent['params'] = serialize( array(
                              '4::target' => $thisLogevent['move']['new_title'],
                              '5::noredir' => $noredirect
                        ) );
                  }
                  if ( isset( $thisLogevent['ip'] ) ) {
                        $thisLogevent['mbqrcip'] = $thisLogevent['usertext'];
                  }
                  if ( isset( $thisLogevent['type'] ) ) {
                        $thisLogevent['mbqrcsource'] = $this->config->sources[$thisLogevent['type']];
                  }
                  // Iterate over those database fields
                  foreach ( $userRow as $thisRowItem ) {
                        if ( !$isFirstInItem ) {
                              $row .= ', ';
                        }
                        $isFirstInItem = false;
                        // If it's a boolean field, 1 if it's there, 0 if not
                        if ( in_array( $thisRowItem, $this->config->booleanFields['rc'] ) ) {
                              if ( isset ( $thisLogevent[ $thisRowItem ] ) ) {
                                    $row .= '1';
                              } else {
                                    $row .= '0';
                              }
                        } else {
                              if ( isset ( $thisLogevent[$thisRowItem] ) ) {
                                    // If it's an array (e.g. tag array), implode it
                                    if ( is_array ( $thisLogevent[$thisRowItem] ) ) {
                                          $thisLogevent[$thisRowItem] = implode ( $thisLogevent[$thisRowItem] );
                                    }
                                    // If it's a string field, escape it
                                    if ( in_array ( $thisRowItem, $this->config->stringFields['rc'] ) ) {
                                          $thisLogevent[$thisRowItem] = "'" . $this->db->real_escape_string
                                                ( $thisLogevent[$thisRowItem] ) . "'";
                                    }
                                    $row .= $thisLogevent[$thisRowItem];
                              } else {
                                    $row .= $this->config->defaultFields['rc'][$thisRowItem];
                              }
                        }
                  }
                  $provisionalRccontinue = 'Y' . $thisLogevent['timestamp'] . '|' . $thisLogevent['rcid'];
                  $row .= ')';
            }
            $row .= ';';
            $queryResult = $this->db->query ( $row );
            if ( $queryResult ) {
                  echo "Inserted " . count( $events ) . " changes successfully!\n";
                  if ( !$rcContinue ) {
                        $rcContinue = $provisionalRccontinue;
                        $skip = true;
                  } else {
                        $skip = false;
                  }
                  // Check cursor existence. If it exists, update it. If it doesn't, then
                  // insert it.
                  if ( $mbcNumRows ) {
                        $query = "UPDATE mb_cursor SET mbc_value='$rcContinue' "
                              . "WHERE mbc_key='rccontinue'";
                  } else {
                        $query = "INSERT INTO mb_cursor (mbc_key, mbc_value) "
                              . " values ('rccontinue', '$rcContinue')";
                  }
                  $success = $this->db->query( $query );
                  if ( !$success ) {
                        die ( "Failed to set cursor!\n" );
                  } else {
                        echo "Set cursor successfully!\n";
                  }
            } else {
                  // Note this failure in the failure log file
                  mirrorGlobalFunctions::logFailure( $this->config, "Failure inserting data\n" );
                  mirrorGlobalFunctions::logFailure( $this->config, $row );
                  mirrorGlobalFunctions::logFailure( $this->config, $this->db->error_list );
                  die();
            }
      }

      function rev() {
            $table = 'mb_queue';
            $where = "mbq_status='needsrev'";
            $options = "ORDER BY mbq_timestamp ASC, mbq_rc_id ASC";
            $ret = $this->db->query( "SELECT * FROM mb_queue "
                  ."WHERE $where LIMIT ". $this->config->revLimit );
            if ( !$ret || !$ret->num_rows ) {
                  echo ( "No $where items\n" );
                  return;
            }
            $value = array();
            $revIds = array();
            while ( $value = $ret->fetch_assoc() ) {
                  $revIds[] = $value[ 'mbq_rev_id' ];
            }
            $firstRevId = true;
            $queryChunk = '';
            foreach( $revIds as $revId ) {
                  if( !$firstRevId ) {
                        $queryChunk .= '|';
                  }
                  $firstRevId = false;
                  $queryChunk .= $revId;
            }
            $data['revids'] = $queryChunk;
            $query = "?action=query&prop=revisions&rvprop=user|comment|content|ids"
                  . "|contentmodel" . '&format=php';
            $ret = $this->wiki->query( $query, $data );
            if ( !$ret ) {
                  echo "Did not retrieve any revisions from query; skipping back around\n";
                  return;
            }
            // Handle revisions of deleted pages. Mark these as rows to ignore unless/until
            // the pages are restored on the remote wiki.
            if ( isset( $ret['query']['badrevids'] ) ) {
                  $badRevs = $ret['query']['badrevids'];
                  foreach ( $badRevs as $badRev ) {
                        $badRevId = $badRev['revid'];
                        $query = 'UPDATE mb_queue SET '
                              . "mbq_status='needsundeletion'"
                              . " WHERE mbq_rev_id="
                              . $badRevId;
                        mirrorGlobalFunctions::doQuery ( $this->db, $this->config, $query,
                              "marking bad revision ID $badRevId as needsundeletion" );
                  }
            }
            if ( !isset( $ret['query']['pages'] ) ) {
                  echo "There was nothing to put in the queue table.\n";
                  return;
            }
            $pages = $ret['query']['pages'];
            foreach ( $pages as $page ) { // Get the particular page...
                  $revisions = $page['revisions'];
                  foreach( $revisions as $revision ) { // Get the particular revision...
                        $content = "''";
                        if ( isset( $revision['*'] ) ) {
                              $content = "'" . $this->db->real_escape_string(
                                    $revision['*'] ) . "'";
                        }
                        $row = 'UPDATE mb_queue SET ';
                        // Iterate over those database fields
                        $isFirstInItem = true;
                        foreach ( $revision as $revisionKey => $revisionValue ) {
                              if ( in_array( $revisionKey, $this->config->fields['rev'] )
                                    && $revisionKey != 'revid' ) {
                                    if ( !$isFirstInItem ) {
                                          $row .= ', ';
                                    }
                                    $isFirstInItem = false;
                                    $row .= array_search( $revisionKey, $this->config->fields['rev'] ) . '=';
                                    // If it's a boolean field, 1 if it's there, 0 if not
                                    if ( in_array( $revisionKey, $this->config->booleanFields['rc'] ) ) {
                                          if ( isset ( $revision[$revisionField] ) ) {
                                                $row .= '1';
                                          } else {
                                                $row .= '0';
                                          }
                                    } else {
                                          // If it's an array (e.g. tag array), implode it
                                          if ( is_array( $revisionValue ) ) {
                                                $revisionValue = implode( '|',
                                                      $revision[$revisionField] );
                                          }
                                          // If it's a string field, escape it
                                          if ( in_array( $revisionKey,
                                                $this->config->stringFields['rev'] ) ) {
                                                $revisionValue = "'" . $this->db->real_escape_string
                                                      ( $revisionValue ) . "'";
                                          }
                                    $row .= $revisionValue;
                                    }
                              }
                        }
                        $deleted = 0;
                        if ( isset( $revision['texthidden'] ) ) {
                              $deleted++;
                        }
                        if ( isset( $revision['commenthidden'] ) ) {
                              $deleted += 2;
                        }
                        if ( isset( $revision['userhidden' ] ) ) {
                              $deleted += 4;
                        }
                        $row .= ",mbq_deleted=$deleted";
                        // Insert the content, and get the ID for that row
                        // TODO: Begin transaction and commit transaction
                        $query = "INSERT INTO mb_text (mbt_text) VALUES ("
                              . $content . ")";
                        $status = mirrorGlobalFunctions::doQuery ( $this->db, $this->config, $query,
                              "inserting text for rev id " . $revision['revid'] );
                        $textId = $this->db->insert_id;
                        // Now update the queue
                        $row .= ",mbq_text_id=$textId"
                              . ",mbq_status='readytopush'"
                              . " WHERE mbq_rev_id="
                              . $revision['revid'];
                        mirrorGlobalFunctions::doQuery ( $this->db, $this->config, $row,
                              'updating rev ' . $revision['revid']
                              . " with text id $textId" );
                  }
            }
      }

      function pagerestorerevids() {
            $mbcId = 0;
            $mbcNumRows = 0;
            $rvContinueForQuery = '';
            $continueValue = null;
            $continueValueArr = null;
            // Get starting rev_id
            $continueResult = $this->db->query( 'SELECT * FROM mb_cursor WHERE'
                  . " mbc_key='mirrorpagerestore-needsrevids-rvcontinue'" );
            if ( $continueResult ) {
                  $continueValueArr = $continueResult->fetch_assoc();
                  $continueValue = $continueValueArr['mbc_value'];
                  $mbcId = $continueValueArr['mbc_id'];
                  $mbcNumRows = $continueResult->num_rows;
                  if ( $continueValue ) {
                        $rvContinueForQuery = "&rvcontinue=$continueValue";
                  }
            }
            // Get the needsrevids row from the mb_queue
            $needsRevIdsWhere = "mbq_action='mirrorpagerestore' AND mbq_status='needsrevids'";
            $needsRevIdsQueryOptions = "";
            if ( $continueValueArr ) {
                  $needsRevIdsWhere .= " AND mbq_rc_id=" . $continueValueArr['mbc_misc'];
            } else {
                  $needsRevIdsQueryOptions = "ORDER BY mbq_rc_id";
            }
            $needsRevIdsQueryOptions .= " LIMIT 1";
            $dbQuery = "SELECT * FROM mb_queue WHERE $needsRevIdsWhere "
                  . $needsRevIdsQueryOptions;
            $needsRevIds = $this->db->query( $dbQuery );
            if ( !$needsRevIds || !$needsRevIds->num_rows ) {
                  echo "No mirrorpagerestore needsrevids items\n";
                  return;
            }
            $needsRevIdsArr = $needsRevIds->fetch_assoc();
            $rcIdForRows = $needsRevIdsArr['mbq_rc_id']; // Put this in the rows' mbq_rc_id2
            $ret = $this->wiki->query ( "?action=query&prop=revisions&pageids="
                  . $needsRevIdsArr['mbq_page_id']
                  . "&rvdir=newer&rvprop=ids|flags|timestamp|user|userid|sha1|contentmodel"
                  . "|comment|size|tags&rvlimit=" . $this->config->revLimit
                  . "$rvContinueForQuery&format=php", true );
            if ( isset( $ret['query-continue']['revisions']['rvcontinue'] ) ) {
                  $rvContinue = $ret['query-continue']['revisions']['rvcontinue'];
            } else {
                  // Delete the cursor and switch over to needsmakeremotelylive at the end of
                  // this
                  $rvContinue = null;
            }
            if ( !isset( $ret['query']['pages'] ) ) {
                  echo "There was nothing to put in the queue table.\n";
                  return;
            }
            $pages = $ret['query']['pages'];
            foreach ( $pages as $page ) { // Get the particular page...
                  // This page's revisions were deleted, apparently, so this will just be a
                  // mirrorlogentry
                  if ( !isset( $page['revisions'] ) ) {
                        $query = "UPDATE mb_queue SET "
                              . "mbq_status='readytopush',mbq_action='mirrorlogentry' "
                              . "WHERE $needsRevIdsWhere ";
                        MirrorGlobalFunctions::doQuery( $this->db,
                              $this->config, $query,
                              'changing mirrorpagerestore needsrevids to '
                              . 'mirrorlogentry readytopush' );
                        return;
                  }
                  $events = $page['revisions'];
                  $table = 'mb_queue';
                  $dbFields = array_keys ( $this->config->fields['pagerestorerevids'] );
                  $userRow = array_values ( $this->config->fields['pagerestorerevids'] );
                  $undesirables = array ( '-', ':', 'T', 'Z' );
                  $row = 'insert into ' . $table . ' ( ' . implode ( ', ', $dbFields ) . ' ) values ';
                  $isFirstInEvent = true;
                  if ( !$events ) {
                        echo "No events\n";
                        return;
                  }
                  foreach ( $events as $thisLogevent ) {
                        $deleted = 0;
                        if ( !$isFirstInEvent ) {
                              $row .= ', ';
                        }
                        $isFirstInEvent = false;
                        $row .= '( ';
                        $isFirstInItem = true;
                        // Get rid of dashes, colons, Ts and Zs in timestamp
                        $thisLogevent['timestamp'] = str_replace ( $undesirables, '',
                              $thisLogevent['timestamp'] );
                        $thisLogevent['mbqaction'] = 'makeremotelylive';
                        $thisLogevent['mbqstatus'] = 'needsmakeremotelylive';
                        $thisLogevent['namespace'] = $needsRevIdsArr['mbq_namespace'];
                        $thisLogevent['title'] = $needsRevIdsArr['mbq_title'];
                        $thisLogevent['mbqrcid'] = 0; // These rows need to be given priority by rev()
                        if ( isset( $thisLogevent['actionhidden'] ) ) {
                              $deleted++;
                        }
                        if ( isset( $thisLogevent['commenthidden'] ) ) {
                              $deleted += 2;
                        }
                        if ( isset( $thisLogevent['userhidden'] ) ) {
                              $deleted += 4;
                        }
                        $thisLogevent['mbqdeleted'] = $deleted;
                        $thisLogevent['mbqrcid2'] = $rcIdForRows;
                        // Iterate over those database fields
                        foreach ( $userRow as $thisRowItem ) {
                              if ( !$isFirstInItem ) {
                                    $row .= ', ';
                              }
                              $isFirstInItem = false;
                              // If it's a boolean field, 1 if it's there, 0 if not
                              if ( in_array( $thisRowItem, $this->config->booleanFields['rc'] ) ) {
                                    if ( isset ( $thisLogevent[ $thisRowItem ] ) ) {
                                          $row .= '1';
                                    } else {
                                          $row .= '0';
                                    }
                              } elseif( isset ( $thisLogevent[$thisRowItem] ) ) {
                                    // If it's an array (e.g. tag array), implode it
                                    if ( is_array ( $thisLogevent[$thisRowItem] ) ) {
                                          // TODO: Figure out what the glue actually should be
                                          $thisLogevent[$thisRowItem] = implode( '|',
                                                $thisLogevent[$thisRowItem] );
                                    }
                                    // If it's a string field, escape it
                                    if ( in_array ( $thisRowItem, $this->config->stringFields['rc'] ) ) {
                                          $thisLogevent[$thisRowItem] = "'" . $this->db->real_escape_string
                                                ( $thisLogevent[$thisRowItem] ) . "'";
                                    }
                                    $row .= $thisLogevent[$thisRowItem];
                              } else {
                                    $row .= $this->config->defaultFields['rc'][$thisRowItem];
                              }
                        }
                        $row .= ')';
                  }
                  $row .= ';';
                  $queryResult = MirrorGlobalFunctions::doQuery( $this->db, $this->config,
                        $row, 'inserting ' . count( $events ) . ' changes' );
                  if ( $queryResult ) {
                        // Check cursor existence. If it exists, update it. If it doesn't, then
                        // insert it.
                        if ( !$rvContinue && $mbcNumRows ) {
                              $query = 'DELETE FROM mb_cursor '
                                    . "WHERE mbc_key='mirrorpagerestore-needsrevids-rvcontinue'";
                        } elseif ( $mbcNumRows ) {
                              $query = "UPDATE mb_cursor SET mbc_value='$rvContinue' "
                                    . "WHERE mbc_key='mirrorpagerestore-needsrevids-rvcontinue'";
                        } else {
                              $query = "INSERT INTO mb_cursor (mbc_key, mbc_value) "
                                    . " values ('mirrorpagerestore-needsrevids-rvcontinue',
                                    '$rvContinue')";
                        }
                        $success = $this->db->query( $query );
                        MirrorGlobalFunctions::doQuery( $this->db, $this->config,
                                    $query, $rvContinue ? 'setting pagerestorerevids cursor'
                                    : 'deleting pagerestorerevids cursor' );
                        // If we've reached the end of the pull, then update the
                        // mirrorpagerestore row accordingly
                        if ( !$rvContinue ) {
                              $query = "UPDATE mb_queue SET "
                                    . "mbq_status='needsmakeremotelylive' "
                                    . "WHERE $needsRevIdsWhere ";
                              MirrorGlobalFunctions::doQuery( $this->db,
                                    $this->config, $query,
                                    'changing mirrorpagerestore needsrevids to '
                                    . 'needsmakeremotelylive' );
                        }
                  }
            }
      }

      function movenullrev() {
            $table = 'mb_queue';
            $where = "mbq_status='needsmovenullrev'";
            $keepLooping = true;
            while ( $keepLooping ) {
                  $ret = $this->db->query( "SELECT * FROM mb_queue "
                        ."WHERE $where LIMIT 1" );
                  if ( !$ret || !$ret->num_rows ) {
                        echo ( "No $where items for nullrev\n" );
                        $keepLooping = false;
                        continue;
                  }
                  $value = $ret->fetch_assoc();
                  $params = $value['mbq_log_params'];
                  $unserialized = unserialize( $params );
                  $prefixedMoveTo = $unserialized['4::target'];
                  $timestamp = $value['mbq_timestamp'];
                  $timestamp = substr( $timestamp, 0, 4 ) . '-'
                        . substr( $timestamp, 4, 2 ) . '-'
                        . substr( $timestamp, 6, 2 ) . 'T'
                        . substr( $timestamp, 8, 2 ) . ':'
                        . substr( $timestamp, 10, 2 ) . ':'
                        . substr( $timestamp, 12, 2 ) . 'Z';
                  $query = "?action=query&prop=revisions&rvprop=comment|ids"
                        . "&titles=$prefixedMoveTo&rvstart=$timestamp&rvlimit=1&format=php";
                  $ret = $this->wiki->query( $query, true );
                  if ( !$ret ) {
                        echo "Did not retrieve any revisions from nullrev query; "
                              . "skipping back around\n";
                        return;
                  }
                  // If there's no comment2 in the mb_queue row yet...
                  if ( !$value['mbq_comment2'] ) {
                        if ( !isset( $ret['query']['badrevids'] ) ) {
                              if ( isset( $ret['query']['pages'] ) ) {
                                    $pages = $ret['query']['pages'];
                                    foreach ( $pages as $page ) { // Get the particular page...
                                          if ( isset( $page['missing'] ) ) {
                                                // These missing pages for comments can't be
                                                // allowed to hold up the works.
                                                echo "Missing page for comment!\n";
                                          } else {
                                                $revisions = $page['revisions'];
                                                // Get the particular revision...
                                                foreach( $revisions as $revision ) {
                                                      $thisRevId = $revision['revid'];
                                                      $comment = $revision['comment'];
                                                      $parentId = $revision['parentid'];
                                                      // Now update the queue
                                                      $query = 'UPDATE mb_queue SET '
                                                            . 'mbq_rev_id=' . $thisRevId
                                                            . ",mbq_rc_last_oldid="
                                                                  . $parentId
                                                            . ",mbq_comment2='"
                                                                  . $this->db->real_escape_string( $comment )
                                                            . "' WHERE mbq_id=" . $value['mbq_id'];
                                                      $status = $this->db->query ( $query );
                                                      if ( $status ) {
                                                            echo "Success updating rev id $thisRevId "
                                                                  ."with comment\n";
                                                      } else {
                                                            // Note this failure in the failure log file
                                                            mirrorGlobalFunctions::logFailure(
                                                                  "Failure updating rev $thisRevId "
                                                                  . "with comment\n" );
                                                            mirrorGlobalFunctions::logFailure(
                                                                  $this->db->error_list );
                                                      }
                                                }
                                          }
                                    }
                              } else {
                                    echo "No query for comment!\n";
                              }
                        } else {
                              // These bad revision IDs for null revisions can't be allowed to hold
                              // up the works.
                              echo "Bad revision for comment!\n";
                        }
                  }
                  // If there's no redirect revision ID in the mb_queue row yet...
                  if ( !$value['mbq_rev_id2'] ) {
                        // Is there a redirect? If not, break, because there's nothing for us to
                        // do here
                        if ( $unserialized['5::noredir'] == '1' ) {
                              $query = 'UPDATE mb_queue SET '
                                    . "mbq_status='readytopush' "
                                    . "WHERE mbq_id=" . $value['mbq_id'];
                              $status = $db->query ( $query );
                              return;
                        }
                        // Get the redirect rev ID
                        $pageId = $value['mbq_page_id'];
                        $query = "?action=query&prop=revisions&rvprop=comment|ids"
                              . "&pageids=$pageId&rvstart=$timestamp&rvlimit=1&format=php";
                        $ret = $this->wiki->query( $query, true );
                        if ( !$ret ) {
                              echo "Did not retrieve any revisions from redirect query; "
                                    . "skipping back around\n";
                              return;
                        }
                        // Handle revisions whose pages on the remote wiki were deleted. These bad
                        // revision IDs for redirect revisions can't be allowed to hold up the
                        // works; the pages must move!
                        if ( isset( $ret['query']['badrevids'] ) ) {
                              $query = 'UPDATE mb_queue SET '
                                    . "mbq_status='readytopush' "
                                    . "WHERE mbq_id=" . $value['mbq_id'];
                              $status = $db->query ( $query );
                              if ( $status ) {
                                    echo "Bad revision ID for page ID $pageId; "
                                          . "ready to push anyway\n";
                              } else {
                                    // Note this failure in the failure log file
                                    mirrorGlobalFunctions::logFailure( "Failure updating\n" );
                                    mirrorGlobalFunctions::logFailure ( $db->error_list );
                              }
                        } elseif ( isset( $ret['query']['pages'] ) ) {
                              $pages = $ret['query']['pages'];
                              foreach ( $pages as $page ) { // Get the particular page...
                                    if ( isset( $page['missing'] ) ) {
                                          // These missing pages for revision IDs can't be
                                          // allowed to hold up the works.
                                          echo "Missing page for null revid!\n";
                                    } else {
                                          $revisions = $page['revisions'];
                                          // Get the particular revision...
                                          foreach( $revisions as $revision ) {
                                                $thisRedirectRevId = $revision['revid'];
                                                // Now update the queue
                                                $query = 'UPDATE mb_queue SET '
                                                      . 'mbq_rev_id2=' . $thisRedirectRevId
                                                      . ",mbq_status='readytopush'"
                                                      . " WHERE mbq_id=" . $value['mbq_id'];
                                                $status = $db->query ( $query );
                                                if ( $status ) {
                                                      echo "Success updating rev id $thisRevId "
                                                            . "with redirect rev id $thisRedirectRevId\n";
                                                } else {
                                                      // Note this failure in the failure log file
                                                      mirrorGlobalFunctions::logFailure( "Failure updating rev " . $value['mbq_rev_id']
                                                            . " with redirect rev id $thisRedirectRevId\n" );
                                                      mirrorGlobalFunctions::logFailure( $db->error_list );
                                                }
                                          }
                                    }
                              }
                              return;
                        } else {
                              echo "No query for redirect revision!\n";
                        }
                  }
            }
      }

      function initialize() {
            // "q" (queue) Four options: -qrc, -qrev, qus, qrcrev (rc and rev)
            // "r" (repeat) Three options: -ro (onetime), -rd (continuous, using defaults),
            // r<number of microsecs to sleep>
            // "s" starting timestamp
            $allowableOptions['q'] = array(
                  'rc',
                  'rev',
                  'nullrev',
                  'movenullrev',
                  'pagerestorerevids',
                  'rcrev'
            );
            $allowableOptions['r'] = array(
                  'o',
                  'd',
            );
            $usage = 'Usage: php mirrorpullbot.php -q<option (e.g. ';
            $firstOption = true;
            foreach( $allowableOptions['q'] as $allowableOption ) {
                  if ( !$firstOption ) {
                        $usage .= ', ';
                  }
                  $usage .= $allowableOption;
                  $firstOption = false;
            }
            $usage .= '> [-r<option (e.g. ro, rd, r<microseconds>>] '
                  .'[-s<starting (e.g. 20120101000000)>])' . "\n";
            if ( !isset( $this->options['q'] ) ) {
                  die( $usage );
            }
            if ( !isset( $this->options['r'] ) ) {
                  $this->options['r'] = 'o'; // Default to onetime
            }
            if ( !in_array( $this->options['q'], $allowableOptions['q'] ) ) {
                  die ( $usage );
            }
            if ( !in_array( $this->options['r'], $allowableOptions['r'] ) ) {
                  if ( !is_numeric( $this->options['r'] ) ) { // Microseconds option
                        echo "You did not select an acceptable option for r\n";
                        die ( $usage );
                  } else {
                        $this->sleepMicroseconds = $this->$options['r'];
                  }
            }
            $this->startingTimestamp = '';
            if ( isset ( $this->options['s'] ) ) {
                  if ( is_numeric ( $this->options['s'] ) ) {
                        if ( $config->options['s'] < 10000000000000
                              || $config->options['s'] > 30000000000000 ) {
                              die( "Error: Timestamp must be after C.E. 1000 and before C.E. 3000\n" );
                        }
                  } else {
                        die( "Starting timestamp supposed to be an integer\n" );
                  }
                  $this->startingTimestamp = $this->options['s'];
            }

            if ( $this->options['r'] == 'd' ) {
                  $this->sleepMicroseconds =
                        $config->defaultMicroseconds['pull'][$this->options['q']];
            }

            $this->wiki = new wikipedia;
            $this->wiki->url = $this->config->remoteWikiUrl[$this->config->remoteWikiName];
            // Login
            if ( !isset( $this->passwordConfig->pullUser[$this->config->remoteWikiName] )
                  || !isset( $this->passwordConfig->pullPass[$this->config->remoteWikiName] ) ) {
                  die( "No login credentials for " . $this->config->remoteWikiName );
            }
            $this->wiki->login( $this->passwordConfig->pullUser[$this->config->remoteWikiName],
                  $this->passwordConfig->pullPass[$this->config->remoteWikiName] );
      }
}