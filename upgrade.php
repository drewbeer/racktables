<?php

require_once 'inc/orm.php';
$relnotes = array
(
	'0.17.0' => "This release requires changes to the configuration file. " .
		"Move inc/secret.php to local/secret.php and add the following to the file:<br><br>" .
		"\$user_auth_src = 'database';<br>\$require_local_account = TRUE;<br><br>" .
		"(and adjust to your needs, if necessary)<br>" .
		"Another change is the addition of support for file uploads.  Files are stored<br>" .
		"in the database.  There are several settings in php.ini which you may need to modify:<br>" .
		"<ul><li>file_uploads        - needs to be On</li>" .
		"<li>upload_max_filesize - max size for uploaded files</li>" .
		"<li>post_max_size       - max size of all form data submitted via POST (including files)</li></ul><br>" .
		"Local user accounts used to have 'enabled' flag, which allowed individual blocking and<br>" .
		"unblocking of each. This flag was dropped in favor of existing mean of access<br>" .
		"setup (RackCode). An unconditional denying rule is automatically added into RackCode<br>" .
		"for such blocked account, so the effective security policy remains the same.<br>",
);

// At the moment we assume, that for any two releases we can
// sequentally execute all batches, that separate them, and
// nothing will break. If this changes one day, the function
// below will have to generate smarter upgrade paths, while
// the upper layer will remain the same.
// Returning an empty array means that no upgrade is necessary.
// Returning NULL indicates an error.
function getDBUpgradePath ($v1, $v2)
{
	$versionhistory = array
	(
		'0.16.4',
		'0.16.5',
		'0.16.6',
		'0.17.0',
		'0.18.0',
	);
	if (!in_array ($v1, $versionhistory) or !in_array ($v2, $versionhistory))
		return NULL;
	$skip = TRUE;
	$path = NULL;
	// Now collect all versions > $v1 and <= $v2
	foreach ($versionhistory as $v)
	{
		if ($skip and $v == $v1)
		{
			$skip = FALSE;
			$path = array();
			continue;
		}
		if ($skip)
			continue;
		$path[] = $v;
		if ($v == $v2)
			break;
	}
	return $path;
}

// Upgrade batches are named exactly as the release where they first appear.
// That is simple, but seems sufficient for beginning.
function executeUpgradeBatch ($batchid)
{
	$query = array();
	global $dbxlink;
	switch ($batchid)
	{
		case '0.16.5':
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('IPV4_TREE_SHOW_USAGE','yes','string','no','no','Show address usage in IPv4 tree')";
			$query[] = "update Config set varvalue = '0.16.5' where varname = 'DB_VERSION'";
			break;
		case '0.16.6':
			$query[] = "update Config set varvalue = '0.16.6' where varname = 'DB_VERSION'";
			break;
		case '0.17.0':
			// create tables for storing files (requires InnoDB support)
			if (!isInnoDBSupported ())
			{
				showError ("Cannot upgrade because InnoDB tables are not supported by your MySQL server. See the README for details.", __FILE__);
				die;
			}

			$query[] = "alter table Chapter change chapter_no id int(10) unsigned NOT NULL auto_increment";
			$query[] = "alter table Dictionary change dict_key id int(10) unsigned NOT NULL auto_increment";
			$query[] = "alter table PortCompat add id int unsigned not null auto_increment PRIMARY KEY";
			$query[] = "alter table Chapter change chapter_name name char(128) NOT NULL";
			$query[] = "alter table Chapter drop key chapter_name";
			$query[] = "alter table Chapter add UNIQUE KEY name (name)";
			$query[] = "alter table Attribute change attr_id id int(10) unsigned NOT NULL auto_increment";
			$query[] = "alter table Attribute change attr_type type enum('string','uint','float','dict') default NULL";
			$query[] = "alter table Attribute change attr_name name char(64) default NULL";
			$query[] = "alter table Attribute drop key attr_name";
			$query[] = "alter table Attribute add UNIQUE KEY name (name)";
			$query[] = "alter table AttributeMap change chapter_no chapter_id int(10) unsigned NOT NULL";
			$query[] = "alter table Dictionary change chapter_no chapter_id int(10) unsigned NOT NULL";
			// Many dictionary changes were made... remove all dictvendor entries and install fresh.
			// Take care not to erase locally added records. 0.16.x ends with max key 797
			$query[] = 'DELETE FROM Dictionary WHERE ((chapter_id BETWEEN 11 AND 14) or (chapter_id BETWEEN 16 AND 19) ' .
				'or (chapter_id BETWEEN 21 AND 24)) and id <= 797';
			$f = fopen ("install/init-dictvendors.sql", 'r');
			if ($f === FALSE)
			{
				showError ("Failed to open install/init-dictvendors.sql for reading");
				die;
			}
			$longq = '';
			while (!feof ($f))
			{
				$line = fgets ($f);
				if (ereg ('^--', $line))
					continue;
				$longq .= $line;
			}
			fclose ($f);
			foreach (explode (";\n", $longq) as $dict_query)
			{
				if (empty ($dict_query))
					continue;
				$query[] = $dict_query;
			}

			// schema changes for file management
			$query[] = "
CREATE TABLE `File` (
  `id` int(10) unsigned NOT NULL auto_increment,
  `name` char(255) NOT NULL,
  `type` char(255) NOT NULL,
  `size` int(10) unsigned NOT NULL,
  `ctime` datetime NOT NULL,
  `mtime` datetime NOT NULL,
  `atime` datetime NOT NULL,
  `contents` longblob NOT NULL,
  `comment` text,
  PRIMARY KEY  (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";
			$query[] = "
CREATE TABLE `FileLink` (
  `id` int(10) unsigned NOT NULL auto_increment,
  `file_id` int(10) unsigned NOT NULL,
  `entity_type` enum('ipv4net','ipv4rspool','ipv4vs','object','rack','user') NOT NULL default 'object',
  `entity_id` int(10) NOT NULL,
  PRIMARY KEY  (`id`),
  UNIQUE KEY `FileLink-unique` (`file_id`,`entity_type`,`entity_id`),
  KEY `FileLink-file_id` (`file_id`),
  CONSTRAINT `FileLink-File_fkey` FOREIGN KEY (`file_id`) REFERENCES `File` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8";
			$query[] = "ALTER TABLE TagStorage MODIFY COLUMN target_realm enum('file','ipv4net','ipv4rspool','ipv4vs','object','rack','user') NOT NULL default 'object'";

			// add network security as an object type
			$query[] = "INSERT INTO `Chapter` (`id`, `sticky`, `name`) VALUES (24,'no','network security models')";
			$query[] = "INSERT INTO `AttributeMap` (`objtype_id`, `attr_id`, `chapter_id`) VALUES (798,1,0)";
			$query[] = "INSERT INTO `AttributeMap` (`objtype_id`, `attr_id`, `chapter_id`) VALUES (798,2,24)";
			$query[] = "INSERT INTO `AttributeMap` (`objtype_id`, `attr_id`, `chapter_id`) VALUES (798,3,0)";
			$query[] = "INSERT INTO `AttributeMap` (`objtype_id`, `attr_id`, `chapter_id`) VALUES (798,5,0)";
			$query[] = "INSERT INTO `AttributeMap` (`objtype_id`, `attr_id`, `chapter_id`) VALUES (798,14,0)";
			$query[] = "INSERT INTO `AttributeMap` (`objtype_id`, `attr_id`, `chapter_id`) VALUES (798,16,0)";
			$query[] = "INSERT INTO `AttributeMap` (`objtype_id`, `attr_id`, `chapter_id`) VALUES (798,17,0)";
			$query[] = "INSERT INTO `AttributeMap` (`objtype_id`, `attr_id`, `chapter_id`) VALUES (798,18,0)";
			$query[] = "INSERT INTO `AttributeMap` (`objtype_id`, `attr_id`, `chapter_id`) VALUES (798,20,0)";
			$query[] = "INSERT INTO `AttributeMap` (`objtype_id`, `attr_id`, `chapter_id`) VALUES (798,21,0)";
			$query[] = "INSERT INTO `AttributeMap` (`objtype_id`, `attr_id`, `chapter_id`) VALUES (798,22,0)";
			$query[] = "INSERT INTO `AttributeMap` (`objtype_id`, `attr_id`, `chapter_id`) VALUES (798,24,0)";
			$query[] = "UPDATE Dictionary SET dict_value = 'Network switch' WHERE dict_key = 8";
			$query[] = 'alter table IPBonds rename to IPv4Allocation';
			$query[] = 'alter table PortForwarding rename to IPv4NAT';
			$query[] = 'alter table IPRanges rename to IPv4Network';
			$query[] = 'alter table IPAddress rename to IPv4Address';
			$query[] = 'alter table IPLoadBalancer rename to IPv4LB';
			$query[] = 'alter table IPRSPool rename to IPv4RSPool';
			$query[] = 'alter table IPRealServer rename to IPv4RS';
			$query[] = 'alter table IPVirtualService rename to IPv4VS';
			$query[] = "alter table TagStorage change column target_realm entity_realm enum('file','ipv4net','ipv4vs','ipv4rspool','object','rack','user') NOT NULL default 'object'";
			$query[] = 'alter table TagStorage change column target_id entity_id int(10) unsigned NOT NULL';
			$query[] = 'alter table TagStorage drop key entity_tag';
			$query[] = 'alter table TagStorage drop key target_id';
			$query[] = 'alter table TagStorage add UNIQUE KEY `entity_tag` (`entity_realm`,`entity_id`,`tag_id`)';
			$query[] = 'alter table TagStorage add KEY `entity_id` (`entity_id`)';
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('PREVIEW_TEXT_MAXCHARS','10240','uint','yes','no','Max chars for text file preview')";
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('PREVIEW_TEXT_ROWS','25','uint','yes','no','Rows for text file preview')";
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('PREVIEW_TEXT_COLS','80','uint','yes','no','Columns for text file preview')";
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('PREVIEW_IMAGE_MAXPXS','320','uint','yes','no','Max pixels per axis for image file preview')";
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('VENDOR_SIEVE','','string','yes','no','Vendor sieve configuration')";
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('IPV4LB_LISTSRC','{$typeid_4}','string','yes','no','List source: IPv4 load balancers')";
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('IPV4OBJ_LISTSRC','{$typeid_4} or {$typeid_7} or {$typeid_8} or {$typeid_12} or {$typeid_445} or {$typeid_447}','string','yes','no','List source: IPv4-enabled objects')";
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('IPV4NAT_LISTSRC','{$typeid_4} or {$typeid_7} or {$typeid_8}','string','yes','no','List source: IPv4 NAT performers')";
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('ASSETWARN_LISTSRC','{$typeid_4} or {$typeid_7} or {$typeid_8}','string','yes','no','List source: object, for which asset tag should be set')";
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('NAMEWARN_LISTSRC','{$typeid_4} or {$typeid_7} or {$typeid_8}','string','yes','no','List source: object, for which common name should be set')";
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('RACKS_PER_ROW','12','unit','yes','no','Racks per row')";
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('FILTER_PREDICATE_SIEVE','','string','yes','no','Predicate sieve regex(7)')";
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('DEFAULT_FILTER_FORMAT','2','string','no','no','Default list filter format')')";
			$query[] = "INSERT INTO `Config` (varname, varvalue, vartype, emptyok, is_hidden, description) VALUES ('DEFAULT_FILTER_BOOLOP','or','string','no','no','Default list filter boolean operation (or/and)')')";
			$query[] = "delete from Config where varname = 'USER_AUTH_SRC'";
			$query[] = "delete from Config where varname = 'COOKIE_TTL'";
			$query[] = "delete from Config where varname = 'rtwidth_0'";
			$query[] = "delete from Config where varname = 'rtwidth_1'";
			$query[] = "delete from Config where varname = 'rtwidth_2'";
			$query[] = "delete from Config where varname = 'NAMEFUL_OBJTYPES'";
			$query[] = "delete from Config where varname = 'REQUIRE_ASSET_TAG_FOR'";
			$query[] = "delete from Config where varname = 'IPV4_PERFORMERS'";
			$query[] = "delete from Config where varname = 'NATV4_PERFORMERS'";
			$query[] = "alter table TagTree add column valid_realm set('file','ipv4net','ipv4vs','ipv4rspool','object','rack','user') not null default 'file,ipv4net,ipv4vs,ipv4rspool,object,rack,user' after parent_id";
			$result = $dbxlink->query ("select user_id, user_name, user_realname from UserAccount where user_enabled = 'no'");
			while ($row = $result->fetch (PDO::FETCH_ASSOC))
				$query[] = "update Script set script_text = concat('deny {\$userid_${row['user_id']}} # ${row['user_name']} (${row['user_realname']})\n', script_text) where script_name = 'RackCode'";
			$query[] = "update Script set script_text = NULL where script_name = 'RackCodeCache'";
			unset ($result);
			$query[] = "alter table UserAccount drop column user_enabled";

			$query[] = "CREATE TABLE RackRow ( id int(10) unsigned NOT NULL auto_increment, name char(255) NOT NULL, PRIMARY KEY  (`id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8";

			$result = $dbxlink->query ("select dict_key, dict_value from Dictionary where chapter_no = 3");
			while($row = $result->fetch(PDO::FETCH_NUM))
			{
				$query[] = "insert into RackRow set id=${row[0]}, name='${row[1]}'";
			}
			$query[] = "delete from Dictionary where chapter_id = 3";
			$query[] = "
CREATE TABLE `LDAPCache` (
  `presented_username` char(64) NOT NULL,
  `successful_hash` char(40) NOT NULL,
  `first_success` timestamp NOT NULL default CURRENT_TIMESTAMP,
  `last_retry` timestamp NOT NULL default '0000-00-00 00:00:00',
  `displayed_name` char(128) default NULL,
  `memberof` text,
  UNIQUE KEY `presented_username` (`presented_username`),
  KEY `scanidx` (`presented_username`,`successful_hash`)
) ENGINE=InnoDB;";
			
			$query[] = "UPDATE Config SET varvalue = '0.17.0' WHERE varname = 'DB_VERSION'";
			break;
		case '0.18.0':

			//revisioning stuff
			$query[] = "create table revision (
				id bigint unsigned not null,
				timestamp datetime not null,
				user_id int unsigned not null )
				engine=InnoDB DEFAULT CHARSET=utf8";
			$query[] = "insert into revision set id=0, timestamp=now(), user_id=1";
			$query[] = "create table Registry (
				id char(64) not null,
				data text,
				primary key(id) )
				engine=InnoDB DEFAULT CHARSET=utf8";
			$query[] = "create table  milestone (
				id int unsigned not null,
				rev bigint unsigned not null,
				user_id int unsigned not null,
				comment text not null )
				engine=InnoDB DEFAULT CHARSET=utf8";
			$query[] = "insert into milestone set id=0, rev=0, user_id=1, comment=''";
			$query[] = "create table operation (
				id int unsigned not null,
				rev bigint unsigned not null,
				user_id int unsigned not null )
				engine=InnoDB DEFAULT CHARSET=utf8";
			$query[] = "insert into operation set id=0, rev=0, user_id=1";

			$query[] = "drop table Atom";
			$query[] = "drop table Molecule";
			$query[] = "drop table MountOperation";
			$query[] = "drop table RackHistory";
			$query[] = "drop table RackObjectHistory";
			//a quick hack to handle the fact that Dictionary changed its dict_key to id
			$dbxlink->exec("alter table Dictionary change dict_key id int unsigned NOT NULL auto_increment");
			$database_meta = DatabaseMeta::$database_meta;
			$noId = array();
			$rev = 0;
			foreach($database_meta as $tname => $tvalue)
			{


				$queryMain = "CREATE TABLE ${tname}__s (
					id int unsigned not null,
					";
				$queryRev = "CREATE TABLE ${tname}__r (
					id int unsigned not null,
					rev bigint unsigned not null,
					rev_terminal tinyint not null,
					";
				$queryViewR = "CREATE VIEW ${tname}__vr as select id, max(rev) as rev from ${tname}__r group by id";
				$queryViewRevFields = '';
				$queryViewStatFields = '';
				foreach ($tvalue['fields'] as $fname=>$fvalue)
				{
					$f = "$fname ".$fvalue['type']." ".($fvalue['null']?'':'not null');
					if ($fvalue['revisioned'])
					{
						$queryRev .= "  $f,\n";
						$queryViewRevFields .= ", ${tname}__r.$fname AS $fname";
					}
					else
					{
						$queryMain .= " $f,\n";
						$queryViewStatFields .= ", ${tname}__s.$fname AS $fname";
					}
				}
				$queryMain .= " key(id)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8
					";
				$queryRev .= "  key(id), key(rev), key(rev_terminal), unique id_rev(id, rev)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8
					";
				$queryView = "CREATE VIEW ${tname} as SELECT ${tname}__s.id AS id $queryViewRevFields $queryViewStatFields FROM ${tname}__r JOIN ${tname}__vr ON ${tname}__r.id = ${tname}__vr.id and ${tname}__r.rev = ${tname}__vr.rev JOIN ${tname}__s ON ${tname}__s.id = ${tname}__r.id WHERE ${tname}__r.rev_terminal = 0";
				$query[] = "drop table $tname";
				$query[] = $queryMain;
				$query[] = $queryRev;
				$query[] = $queryViewR;
				$query[] = $queryView;
				$revFields = array();
				$revValues = array();
				$statFields = array();
				$statValues = array();
				foreach($tvalue['fields'] as $fname=>$fvalue)
				{
					if ($fvalue['revisioned'] == true)
					{
						$revFields[] = $fname;
						$revValues[] = '?';
					}
					else
					{
						$statFields[] = $fname;
						$statValues[] = '?';
					}
				}

				$idFound = false;
				$q = $dbxlink->query("describe $tname");
				while($row = $q->fetch())
				{
					$idFound = $idFound || ($row['Field'] == 'id');
				}
				$q->closeCursor();

				$q = $dbxlink->query("select * from $tname");

				if (!$idFound)
				{
					$noId[] = $tname;
					$id = 1;
					while($row = $q->fetch())
					{
						$posValue = 0;
						foreach($statFields as $f)
						{
							if ($row[$f] === NULL)
								$statValues[$posValue++] = 'NULL';
							else
								$statValues[$posValue++] = "'".mysql_real_escape_string($row[$f])."'"; 
						}

						$posValue = 0;
						foreach($revFields as $f)
						{
							if ($row[$f] === NULL)
								$revValues[$posValue++] = 'NULL';
							else
								$revValues[$posValue++] = "'".mysql_real_escape_string($row[$f])."'"; 
						}

						$sqls = "insert into ${tname}__s (id".(count($statFields)>0?',':'')." ".implode(', ', $statFields).") values ($id".(count($statValues)>0?',':'')." ".implode(', ', $statValues).")";

						$sqlr = "insert into ${tname}__r (id, rev, rev_terminal".(count($revFields)>0?',':'')." ".implode(', ', $revFields).") values ($id, $rev, false".(count($revValues)>0?',':'')." ".implode(', ', $revValues).")";

						$query[] = $sqls;
						$query[] = $sqlr;
						$id++;
					}
				}
				else
				{
					$statFields[] = 'id';
					$revFields[] = 'id';
					while($row = $q->fetch())
					{
						$posValue = 0;
						foreach($statFields as $f)
						{
							if ($row[$f] === NULL)
								$statValues[$posValue++] = 'NULL';
							else
								$statValues[$posValue++] = "'".mysql_real_escape_string($row[$f])."'"; 
						}

						$posValue = 0;
						foreach($revFields as $f)
						{
							if ($row[$f] === NULL)
								$revValues[$posValue++] = 'NULL';
							else
								$revValues[$posValue++] = "'".mysql_real_escape_string($row[$f])."'"; 
						}

						$sqls = "insert into ${tname}__s (".implode(', ', $statFields).") values (".implode(', ', $statValues).")";

						$sqlr = "insert into ${tname}__r (rev, rev_terminal".(count($revFields)>0?',':'')." ".implode(', ', $revFields).") values ($rev, false".(count($revValues)>0?',':'')." ".implode(', ', $revValues).")";

						$query[] = $sqls;
						$query[] = $sqlr;

					}
				}
				$q->closeCursor();
			}
			$query[] = "UPDATE Config SET varvalue = '0.18.0' WHERE varname = 'DB_VERSION'";
			break;
		default:
			showError ("executeUpgradeBatch () failed, because batch '${batchid}' isn't defined", __FILE__);
			die;
			break;
	}
	$failures = array();
	echo "<tr><th>Executing batch '${batchid}'</th><td>";
	foreach ($query as $q)
	{
		$result = $dbxlink->query ($q);
		if ($result == NULL)
		{
			$errorInfo = $dbxlink->errorInfo();
			$failures[] = array ($q, $errorInfo[2]);
		}
	}
	if (!count ($failures))
		echo "<strong><font color=green>done</font></strong>";
	else
	{
		echo "<strong><font color=red>The following queries failed:</font></strong><br><pre>";
		foreach ($failures as $f)
		{
			list ($q, $i) = $f;
			echo "${q} -- ${i}\n";
		}
		echo "</pre>";
	}
	echo '</td></tr>';
}

// ******************************************************************
//
//                  Execution starts here
//
// ******************************************************************

$root = (empty($_SERVER['HTTPS']) or $_SERVER['HTTPS'] == 'off') ? 'http://' : 'https://';
$root .= isset ($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ($_SERVER['SERVER_NAME'].($_SERVER['SERVER_PORT']=='80'?'':$_SERVER['SERVER_PORT']));
$root .= strtr (dirname ($_SERVER['PHP_SELF']), '\\', '/');
if (substr ($root, -1) != '/')
	$root .= '/';

// The below will be necessary as long as we rely on showError()
require_once 'inc/interface.php';

require_once 'inc/config.php';
require_once 'inc/database.php';
require_once 'inc/revdatabase.php';
if (file_exists ('local/secret.php'))
	require_once 'local/secret.php';
else
	die ("Database connection parameters are read from local/secret.php file, " .
		"which cannot be found.\nCopy provided config/secret-sample.php to " .
		"local/secret.php and modify to your setup.\n\nThen reload the page.");

try
{
	$dbxlink = new PDO ($pdo_dsn, $db_username, $db_password);
}
catch (PDOException $e)
{
	die ("Database connection failed:\n\n" . $e->getMessage());
}

$dbxlink->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbxlink->exec ("set names 'utf8'");

Database::init($dbxlink);


// Now we need to be sure that the current user is the administrator.
// The rest doesn't matter within this context.

function authenticate_admin ($username, $password)
{
	global $dbxlink;
	$hash = sha1 ($password);
	$query = "select count(*) from UserAccount where user_id = 1 and user_name = '${username}' and user_password_hash = '${hash}'";
	if (($result = $dbxlink->query ($query)) == NULL)
		die ('SQL query failed in ' . __FUNCTION__);
	$rows = $result->fetchAll (PDO::FETCH_NUM);
	return $rows[0][0] == 1;
}

switch ($user_auth_src)
{
	case 'database':
	case 'ldap': // authenticate against DB as well
		if
		(
			!isset ($_SERVER['PHP_AUTH_USER']) or
			!strlen ($_SERVER['PHP_AUTH_USER']) or
			!isset ($_SERVER['PHP_AUTH_PW']) or
			!strlen ($_SERVER['PHP_AUTH_PW']) or
			!authenticate_admin (escapeString ($_SERVER['PHP_AUTH_USER']), escapeString ($_SERVER['PHP_AUTH_PW']))
		)
		{
			header ('WWW-Authenticate: Basic realm="RackTables upgrade"');
			header ('HTTP/1.0 401 Unauthorized');
			showError ('You must be authenticated as an administrator to complete the upgrade.', __FILE__);
			die;
		}
		break; // cleared
	case 'httpd':
		if
		(
			!isset ($_SERVER['REMOTE_USER']) or
			!strlen ($_SERVER['REMOTE_USER'])
		)
		{
			showError ('System misconfiguration. The web-server didn\'t authenticate the user, although ought to do.');
			die;
		}
		break; // cleared
	default:
		showError ('authentication source misconfiguration', __FILE__);
		die;
}

$dbver = getDatabaseVersion();
echo '<table border=1>';
echo "<tr><th>Current status</th><td>Data version: ${dbver}<br>Code version: " . CODE_VERSION . "</td></tr>\n";

$path = getDBUpgradePath ($dbver, CODE_VERSION);
if ($path === NULL)
{
	echo "<tr><th>Upgrade path</th><td><font color=red>not found</font></td></tr>\n";
	echo "<tr><th>Summary</th><td>Check README for more information.</td></tr>\n";
}
else
{
	if (!count ($path))
		echo "<tr><th>Summary</th><td>Come back later.</td></tr>\n";
	else
	{
		echo "<tr><th>Upgrade path</th><td>${dbver} &rarr; " . implode (' &rarr; ', $path) . "</td></tr>\n";
		foreach ($path as $batchid)
		{
			executeUpgradeBatch ($batchid);
			if (isset ($relnotes[$batchid]))
				echo "<tr><th>Release notes for ${batchid}</th><td>" . $relnotes[$batchid] . "</td></tr>\n";
		}
		echo "<tr><th>Summary</th><td>Upgrade complete, it is Ok to <a href='${root}'>enter</a> the system.</td></tr>\n";
	}
}
echo '</table>';

?>
