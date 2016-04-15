<?php
	/*
		Class: BigTree\SQL
			A MySQL helper class that wraps the pre-4.3 functions.
			When BigTree is bootstrapped, $db is an instance of this class.
	*/

	namespace BigTree;
	
	use mysqli;

	class SQL {

		static $Connection = "disconnected";
		static $ErrorLog = array();
		static $MySQLTime = false;
		static $QueryLog = array();
		static $WriteConnection = "disconnected";

		public $ActiveQuery = false;

		// Constructor for chain queries
		function __construct($chain_query = false) {
			// Chained instances should use the primary connection
			if ($chain_query) {
				$this->ActiveQuery = $chain_query;
			}
		}

		// A little hack to allow fetch to be called both statically and chained
		function __call($method, $arguments) {
			if ($method == "fetch") {
				return call_user_func_array(array($this, "_local_fetch"), $arguments);
			} elseif ($method == "fetchAll") {
				return call_user_func_array(array($this, "_local_fetchAll"), $arguments);
			} elseif ($method == "fetchAllSingle") {
				return call_user_func_array(array($this, "_local_fetchAllSingle"), $arguments);
			} elseif ($method == "fetchSingle") {
				return call_user_func_array(array($this, "_local_fetchSingle"), $arguments);
			} elseif ($method == "rows") {
				return call_user_func_array(array($this, "_local_rows"), $arguments);
			}
		}

		static function __callStatic($method, $arguments) {
			if ($method == "fetch") {
				return call_user_func_array("static::_static_fetch", $arguments);
			} elseif ($method == "fetchAll") {
				return call_user_func_array("static::_static_fetchAll", $arguments);
			} elseif ($method == "fetchAllSingle") {
				return call_user_func_array("static::_static_fetchAllSingle", $arguments);
			} elseif ($method == "fetchSingle") {
				return call_user_func_array("static::_static_fetchSingle", $arguments);
			} elseif ($method == "rows") {
				return call_user_func_array("static::_static_rows", $arguments);
			}
		}

		/*
			Function: backup
				Backs up the entire database to a given file.

			Parameters:
				file - Full file path to dump the database to.

			Returns:
				true if successful.
		*/

		static function backup($file) {
			if (!FileSystem::getDirectoryWritability($file)) {
				return false;
			}

			$pointer = fopen($file, "w");
			fwrite($pointer, "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n");
			fwrite($pointer, "SET foreign_key_checks = 0;\n\n");

			$tables = static::fetchAllSingle("SHOW TABLES");
			foreach ($tables as $table) {
				// Write the drop / create statements
				fwrite($pointer, "DROP TABLE IF EXISTS `$table`;\n");
				$definition = static::fetchSingle("SHOW CREATE TABLE `$table`");
				if (is_array($definition)) {
					fwrite($pointer, str_replace(array("\n  ", "\n"), "", end($definition)).";\n");
				}

				// Get all the table contents, write them out
				$rows = static::tableContents($table);
				foreach ($rows as $row) {
					fwrite($pointer, $row.";\n");
				}
				
				// Separate it from the next table
				fwrite($pointer, "\n");
			}

			fwrite($pointer, "SET foreign_key_checks = 1;");
			fclose($pointer);

			return true;
		}

		/*
			Function: compareTables
				Returns a list of SQL commands required to turn one table into another.

			Parameters:
				table_a - The table that is being translated
				table_b - The table that the first table will become

			Returns:
				An array of SQL calls to perform to turn Table A into Table B.
		*/

		static function compareTables($table_a, $table_b) {
			// Get table A's description
			$table_a_description = static::describeTable($table_a);
			$table_a_columns = $table_a_description["columns"];
			// Get table B's description
			$table_b_description = static::describeTable($table_b);
			$table_b_columns = $table_b_description["columns"];

			// Setup up query array
			$queries = array();

			// Transition columns
			$last_key = "";
			foreach ($table_b_columns as $key => $column) {
				$action = "";
				// If this column doesn't exist in the Table A table, add it.
				if (!isset($table_a_columns[$key])) {
					$action = "ADD";
				} elseif ($table_a_columns[$key] !== $column) {
					$action = "MODIFY";
				}

				if ($action) {
					$mod = "ALTER TABLE `$table_a` $action COLUMN `$key` ".$column["type"];
					if ($column["size"]) {
						$mod .= "(".$column["size"].")";
					}

					if ($column["unsigned"]) {
						$mod .= " UNSIGNED";
					}

					if ($column["charset"]) {
						$mod .= " CHARSET ".$column["charset"];
					}

					if ($column["collate"]) {
						$mod .= " COLLATE ".$column["collate"];
					}

					if (!$column["allow_null"]) {
						$mod .= " NOT NULL";
					} else {
						$mod .= " NULL";
					}

					if (isset($column["default"])) {
						$d = $column["default"];
						if ($d == "CURRENT_TIMESTAMP" || $d == "NULL") {
							$mod .= " DEFAULT $d";
						} else {
							$mod .= " DEFAULT '".static::escape($d)."'";
						}
					}

					if ($last_key) {
						$mod .= " AFTER `$last_key`";
					} else {
						$mod .= " FIRST";
					}

					$queries[] = $mod;
				}

				$last_key = $key;
			}

			// Drop columns
			foreach ($table_a_columns as $key => $column) {
				// If this key no longer exists in the new table, we should delete it.
				if (!isset($table_b_columns[$key])) {
					$queries[] = "ALTER TABLE `$table_a` DROP COLUMN `$key`";
				}
			}

			// Add new indexes
			foreach ($table_b_description["indexes"] as $key => $index) {
				if (!isset($table_a_description["indexes"][$key]) || $table_a_description["indexes"][$key] != $index) {
					$pieces = array();
					foreach ($index["columns"] as $column) {
						if ($column["length"]) {
							$pieces[] = "`".$column["column"]."`(".$column["length"].")";
						} else {
							$pieces[] = "`".$column["column"]."`";
						}
					}
					$verb = isset($table_a_description["indexes"][$key]) ? "MODIFY" : "ADD";
					$queries[] = "ALTER TABLE `$table_a` $verb ".($index["unique"] ? "UNIQUE " : "")."KEY `$key` (".implode(", ", $pieces).")";
				}
			}

			// Drop old indexes
			foreach ($table_a_description["indexes"] as $key => $index) {
				if (!isset($table_b_description["indexes"][$key])) {
					$queries[] = "ALTER TABLE `$table_a` DROP KEY `$key`";
				}
			}

			// Drop old foreign keys -- we do this for all the existing foreign keys that don't directly match because we're going to regenrate key names
			foreach ($table_a_description["foreign_keys"] as $key => $definition) {
				$exists = false;
				foreach ($table_b_description["foreign_keys"] as $d) {
					if ($d == $definition) {
						$exists = true;
					}
				}
				if (!$exists) {
					$queries[] = "ALTER TABLE `$table_a` DROP FOREIGN KEY `$key`";
				}
			}

			// Import foreign keys
			foreach ($table_b_description["foreign_keys"] as $key => $definition) {
				$exists = false;
				foreach ($table_a_description["foreign_keys"] as $d) {
					if ($d == $definition) {
						$exists = true;
					}
				}
				if (!$exists) {
					$source = $destination = array();
					foreach ($definition["local_columns"] as $column) {
						$source[] = "`$column`";
					}
					foreach ($definition["other_columns"] as $column) {
						$destination[] = "`$column`";
					}
					$query = "ALTER TABLE `$table_a` ADD FOREIGN KEY (".implode(", ", $source).") REFERENCES `".$definition["other_table"]."`(".implode(", ", $destination).")";
					if ($definition["on_delete"]) {
						$query .= " ON DELETE ".$definition["on_delete"];
					}
					if ($definition["on_update"]) {
						$query .= " ON UPDATE ".$definition["on_update"];
					}
					$queries[] = $query;
				}
			}

			// Drop existing primary key if it's not the same
			if ($table_a_description["primary_key"] != $table_b_description["primary_key"]) {
				$pieces = array();
				foreach (array_filter((array) $table_b_description["primary_key"]) as $piece) {
					$pieces[] = "`$piece`";
				}
				$queries[] = "ALTER TABLE `$table_a` DROP PRIMARY KEY";
				$queries[] = "ALTER TABLE `$table_a` ADD PRIMARY KEY (".implode(",", $pieces).")";
			}

			// Switch engine if different
			if ($table_a_description["engine"] != $table_b_description["engine"]) {
				$queries[] = "ALTER TABLE `$table_a` ENGINE = ".$table_b_description["engine"];
			}

			// Switch character set if different
			if ($table_a_description["charset"] != $table_b_description["charset"]) {
				$queries[] = "ALTER TABLE `$table_a` CHARSET = ".$table_b_description["charset"];
			}

			// Switch auto increment if different
			if (isset($table_b_description["auto_increment"]) && $table_a_description["auto_increment"] != $table_b_description["auto_increment"]) {
				$queries[] = "ALTER TABLE `$table_a` AUTO_INCREMENT = ".$table_b_description["auto_increment"];
			}
			
			return $queries;
		}

		/*
			Function: connect
				Sets up the internal connections to the MySQL server(s).
		*/

		static function connect($property, $type) {
			global $bigtree;

			// Initializing optional params, if they don't exist yet due to older install
			!empty($bigtree["config"][$type]["host"]) || $bigtree["config"][$type]["host"] = null;
			!empty($bigtree["config"][$type]["port"]) || $bigtree["config"][$type]["port"] = 3306;
			!empty($bigtree["config"][$type]["socket"]) || $bigtree["config"][$type]["socket"] = null;

			static::${$property} = new mysqli(
				$bigtree["config"][$type]["host"],
				$bigtree["config"][$type]["user"],
				$bigtree["config"][$type]["password"],
				$bigtree["config"][$type]["name"],
				$bigtree["config"][$type]["port"],
				$bigtree["config"][$type]["socket"]
			);

			// Make sure everything is run in UTF8, turn off strict mode if set
			static::${$property}->query("SET NAMES 'utf8'");
			static::${$property}->query("SET SESSION sql_mode = ''");

			// Remove BigTree connection parameters once it is setup.
			unset($bigtree["config"][$type]["user"]);
			unset($bigtree["config"][$type]["password"]);

			return static::${$property};
		}

		/*
			Function: delete
				Deletes a row in the given table

			Parameters:
				table - The table to insert a row into
				id - The ID of the row to delete (or an associate array of key/value pairs to match)

			Returns:
				true if successful (even if no rows match)
		*/

		static function delete($table, $id) {
			$values = $where = array();

			// If the ID is an associative array we match based on the given columns
			if (is_array($id)) {
				foreach ($id as $column => $value) {
					$where[] = "`$column` = ?";
					array_push($values, $value);
				}
				// Otherwise default to id
			} else {
				$where[] = "`id` = ?";
				array_push($values, $id);
			}

			// Add the query and the id parameter into the function parameters
			array_unshift($values, "DELETE FROM `$table` WHERE ".implode(" AND ", $where));

			// Call BigTree\SQL::query
			$response = call_user_func_array("static::query", $values);

			return $response->ActiveQuery ? true : false;
		}

		/*
			Function: describeTable
				Gives in depth information about a MySQL table's structure and keys.
			
			Parameters:
				table - The table name.
			
			Returns:
				An array of table information.
		*/
		
		static function describeTable($table) {
			$result = array(
				"columns" => array(),
				"indexes" => array(),
				"foreign_keys" => array(),
				"primary_key" => array()
			);
			$options = array();
			
			$show_statement = static::fetch("SHOW CREATE TABLE `".str_replace("`", "", $table)."`");
			if (!$show_statement) {
				return false;
			}

			$lines = explode("\n", $show_statement["Create Table"]);
			// Line 0 is the create line and the last line is the collation and such. Get rid of them.
			$main_lines = array_slice($lines, 1, -1);
			foreach ($main_lines as $line) {
				$column = array();
				$line = rtrim(trim($line), ",");
				if (strtoupper(substr($line, 0, 3)) == "KEY" || strtoupper(substr($line, 0, 10)) == "UNIQUE KEY") { // Keys
					if (strtoupper(substr($line, 0, 10)) == "UNIQUE KEY") {
						$line = substr($line, 12); // Take away "KEY `"
						$unique = true;
					} else {
						$line = substr($line, 5); // Take away "KEY `"
						$unique = false;
					}
					// Get the key's name.
					$key_name = static::nextColumnDefinition($line);
					// Get the key's content
					$line = substr($line, strlen($key_name) + substr_count($key_name, "`") + 4); // Skip ` (`
					$line = substr(rtrim($line, ","), 0, -1); // Remove trailing , and )
					$key_parts = array();
					$part = true;
					while ($line && $part) {
						$part = static::nextColumnDefinition($line);
						$size = false;
						// See if there's a size definition, include it
						if (substr($line, strlen($part) + 1, 1) == "(") {
							$line = substr($line, strlen($part) + 1);
							$size = substr($line, 1, strpos($line, ")") - 1);
							$line = substr($line, strlen($size) + 4);
						} else {
							$line = substr($line, strlen($part) + substr_count($part, "`") + 3);
						}
						if ($part) {
							$key_parts[] = array("column" => $part, "length" => $size);
						}
					}
					$result["indexes"][$key_name] = array("unique" => $unique, "columns" => $key_parts);
				} elseif (strtoupper(substr($line, 0, 7)) == "PRIMARY") { // Primary Keys
					$line = substr($line, 14); // Take away PRIMARY KEY (`
					$key_parts = array();
					$part = true;
					while ($line && $part) {
						$part = static::nextColumnDefinition($line);
						$line = substr($line, strlen($part) + substr_count($part, "`") + 3);
						if ($part) {
							if (strpos($part, "KEY_BLOCK_SIZE=") === false) {
								$key_parts[] = $part;
							}
						}
					}
					$result["primary_key"] = $key_parts;
				} elseif (strtoupper(substr($line, 0, 10)) == "CONSTRAINT") { // Foreign Keys
					$line = substr($line, 12); // Remove CONSTRAINT `
					$key_name = static::nextColumnDefinition($line);
					$line = substr($line, strlen($key_name) + substr_count($key_name, "`") + 16); // Remove ` FOREIGN KEY (`
					
					// Get local reference columns
					$local_columns = array();
					$part = true;
					$end = false;
					while (!$end && $part) {
						$part = static::nextColumnDefinition($line);
						$line = substr($line, strlen($part) + 1); // Take off the trailing `
						if (substr($line, 0, 1) == ")") {
							$end = true;
						} else {
							$line = substr($line, 2); // Skip the ,`
						}
						$local_columns[] = $part;
					}

					// Get other table name
					$line = substr($line, 14); // Skip ) REFERENCES `
					$other_table = static::nextColumnDefinition($line);
					$line = substr($line, strlen($other_table) + substr_count($other_table, "`") + 4); // Remove ` (`

					// Get other table columns
					$other_columns = array();
					$part = true;
					$end = false;
					while (!$end && $part) {
						$part = static::nextColumnDefinition($line);
						$line = substr($line, strlen($part) + 1); // Take off the trailing `
						if (substr($line, 0, 1) == ")") {
							$end = true;
						} else {
							$line = substr($line, 2); // Skip the ,`
						}
						$other_columns[] = $part;
					}

					$line = substr($line, 2); // Remove )
					
					// Setup our keys
					$result["foreign_keys"][$key_name] = array("local_columns" => $local_columns, "other_table" => $other_table, "other_columns" => $other_columns);

					// Figure out all the on delete, on update stuff
					$pieces = explode(" ", $line);
					$on_hit = false;
					$current_key = "";
					$current_val = "";
					foreach ($pieces as $piece) {
						if ($on_hit) {
							$current_key = strtolower("on_".$piece);
							$on_hit = false;
						} elseif (strtoupper($piece) == "ON") {
							if ($current_key) {
								$result["foreign_keys"][$key_name][$current_key] = $current_val;
								$current_key = "";
								$current_val = "";
							}
							$on_hit = true;
						} else {
							$current_val = trim($current_val." ".$piece);
						}
					}
					if ($current_key) {
						$result["foreign_keys"][$key_name][$current_key] = $current_val;
					}
				} elseif (substr($line, 0, 1) == "`") { // Column Definition
					$line = substr($line, 1); // Get rid of the first `
					$key = static::nextColumnDefinition($line); // Get the column name.
					$line = substr($line, strlen($key) + substr_count($key, "`") + 2); // Take away the key from the line.
					
					$size = $current_option = "";
					// We need to figure out if the next part has a size definition
					$parts = explode(" ", $line);
					if (strpos($parts[0], "(") !== false) { // Yes, there's a size definition
						$type = "";
						// We're going to walk the string finding out the definition.
						$in_quotes = false;
						$finished_type = false;
						$finished_size = false;
						$x = 0;
						$options = array();
						while (!$finished_size) {
							$c = substr($line, $x, 1);
							if (!$finished_type) { // If we haven't finished the type, keep working on it.
								if ($c == "(") { // If it's a (, we're starting the size definition
									$finished_type = true;
								} else { // Keep writing the type
									$type .= $c;
								}
							} else { // We're finished the type, working in size definition
								if (!$in_quotes && $c == ")") { // If we're not in quotes and we encountered a ) we've hit the end of the size
									$finished_size = true;
								} else {
									if ($c == "'") { // Check on whether we're starting a new option, ending an option, or adding to an option.
										if (!$in_quotes) { // If we're not in quotes, we're starting a new option.
											$current_option = "";
											$in_quotes = true;
										} else {
											if (substr($line, $x + 1, 1) == "'") { // If there's a second ' after this one, it's escaped.
												$current_option .= "'";
												$x++;
											} else { // We closed an option, add it to the list.
												$in_quotes = false;
												$options[] = $current_option;
											}
										}
									} else { // It's not a quote, it's content.
										if ($in_quotes) {
											$current_option .= $c;
										} elseif ($c != ",") { // We ignore commas, they're just separators between ENUM options.
											$size .= $c;
										}
									}
								}
							}
							$x++;
						}
						$line = substr($line, $x);
					} else { // No size definition
						$type = $parts[0];
						$line = substr($line, strlen($type) + 1);
					}
					
					$column["name"] = $key;
					$column["type"] = $type;
					if ($size) {
						$column["size"] = $size;
					}
					if ($type == "enum") {
						$column["options"] = $options;
					}
					$column["allow_null"] = true;
					$extras = explode(" ", $line);
					$extras_count = count($extras);
					for ($x = 0; $x < $extras_count; $x++) {
						$part = strtoupper($extras[$x]);
						if ($part == "NOT" && strtoupper($extras[$x + 1]) == "NULL") {
							$column["allow_null"] = false;
							$x++; // Skip NULL
						} elseif ($part == "CHARACTER" && strtoupper($extras[$x + 1]) == "SET") {
							$column["charset"] = $extras[$x + 2];
							$x += 2;
						} elseif ($part == "DEFAULT") {
							$default = "";
							$x++;
							if (substr($extras[$x], 0, 1) == "'") {
								while (substr($default, -1, 1) != "'") {
									$default .= " ".$extras[$x];
									$x++;
								}
							} else {
								$default = $extras[$x];
							}
							$column["default"] = trim(trim($default), "'");
						} elseif ($part == "COLLATE") {
							$column["collate"] = $extras[$x + 1];
							$x++;
						} elseif ($part == "ON") {
							$column["on_".strtolower($extras[$x + 1])] = $extras[$x + 2];
							$x += 2;
						} elseif ($part == "AUTO_INCREMENT") {
							$column["auto_increment"] = true;
						} elseif ($part == "UNSIGNED") {
							$column["unsigned"] = true;
						}
					}
					
					$result["columns"][$key] = $column;
				}
			}
			
			$last_line = substr(end($lines), 2);
			$parts = explode(" ", $last_line);
			foreach ($parts as $part) {
				list($key, $value) = explode("=", $part);
				if ($key && $value) {
					$result[strtolower($key)] = $value;
				}
			}
			
			return $result;
		}

		/*
			Function: drawColumnSelectOptions
				Draws the <select> options of all the columns in a table.
			
			Parameters:
				table - The table to draw the columns for.
				default - The currently selected value.
				sorting - Whether to duplicate columns into "ASC" and "DESC" versions.
		*/
		
		static function drawColumnSelectOptions($table, $default = "", $sorting = false) {
			$table_description = static::describeTable($table);
			
			if (!$table_description) {
				echo '<option>ERROR: Table Missing</option>';

				return;
			}

			echo '<option></option>';

			foreach ($table_description["columns"] as $col) {
				if ($sorting) {
					if ($default == $col["name"]." ASC" || $default == "`".$col["name"]."` ASC") {
						echo '<option selected="selected">`'.$col["name"].'` ASC</option>';
					} else {
						echo '<option>`'.$col["name"].'` ASC</option>';
					}
					
					if ($default == $col["name"]." DESC" || $default == "`".$col["name"]."` DESC") {
						echo '<option selected="selected">`'.$col["name"].'` DESC</option>';
					} else {
						echo '<option>`'.$col["name"].'` DESC</option>';
					}
				} else {
					if ($default == $col["name"]) {
						echo '<option selected="selected">'.$col["name"].'</option>';
					} else {
						echo '<option>'.$col["name"].'</option>';
					}
				}
			}
		}
		
		/*
			Function: drawTableSelectOptions
				Draws the <select> options for all of tables in the database excluding bigtree_ prefixed tables.
			
			Parameters:
				default - The currently selected value.
		*/
		
		static function drawTableSelectOptions($default = "") {
			global $bigtree;
			
			$tables = static::fetchAllSingle("SHOW TABLES");

			foreach ($tables as $table_name) {
				if (isset($bigtree["config"]["show_all_tables_in_dropdowns"]) || ((substr($table_name, 0, 8) !== "bigtree_")) || $table_name == $default) {
					if ($default == $table_name) {
						echo '<option selected="selected">'.$table_name.'</option>';
					} else {
						echo '<option>'.$table_name.'</option>';
					}
				}
			}
		}

		/*
			Function: dumpTable
				Returns an array of INSERT statements for the rows of a given table.
				The INSERT statements will be binary safe with binary columns requested in hex.

			Parameters:
				table - Table to pull data from.

			Returns:
				An array.
		*/

		static function dumpTable($table) {
			$inserts = array();

			// Figure out which columns are binary and need to be pulled as hex
			$description = static::describeTable($table);
			$column_query = array();
			$binary_columns = array();
			foreach ($description["columns"] as $key => $column) {
				if ($column["type"] == "tinyblob" || $column["type"] == "blob" || $column["type"] == "mediumblob" || $column["type"] == "longblob" || $column["type"] == "binary" || $column["type"] == "varbinary") {
					$column_query[] = "HEX(`$key`) AS `$key`";
					$binary_columns[] = $key;
				} else {
					$column_query[] = "`$key`";
				}
			}

			// Get the rows out of the table
			$query = static::query("SELECT ".implode(", ", $column_query)." FROM `$table`");
			while ($row = $query->fetch()) {
				$keys = $vals = array();

				foreach ($row as $key => $val) {
					$keys[] = "`$key`";
					if ($val === null) {
						$vals[] = "NULL";
					} else {
						if (in_array($key, $binary_columns)) {
							$vals[] = "X'".str_replace("\n", "\\n", static::escape($val))."'";
						} else {
							$vals[] = "'".str_replace("\n", "\\n", static::escape($val))."'";
						}
					}
				}
				$inserts[] = "INSERT INTO `$table` (".implode(",", $keys).") VALUES (".implode(",", $vals).")";
			}

			return $inserts;
		}

		/*
			Function: escape
				Equivalent to mysql_real_escape_string.
				Escapes non-string values by first encoding them as JSON.

			Parameters:
				string - Value to escape

			Returns:
				Escaped string
		*/

		static function escape($string) {
			if (is_object($string) || is_array($string)) {
				$string = JSON::encode($string);
			}
			
			$connection = (static::$Connection && static::$Connection !== "disconnected") ? static::$Connection : static::connect("Connection", "db");

			return $connection->real_escape_string($string);
		}

		/*
			Function: exists
				Checks to see if an entry exists for given key/value pairs.

			Parameters:
				table - The table to search
				values - An array of key/value pairs to match against (i.e. "id" => "10") or just an ID

			Returns:
				true if a row already exists that matches the passed in key/value pairs.
		*/

		static function exists($table, $values) {
			// Passing an array of key/value pairs
			if (is_array($values)) {
				$where = array();
				foreach ($values as $key => $value) {
					$where[] = "`$key` = ?";
				}
				// Allow for just passing an ID
			} else {
				$where = array("`id` = ?");
				$values = array($values);
			}

			// Push the query onto the array stack so it's the first query parameter
			array_unshift($values, "SELECT 1 FROM `$table` WHERE ".implode(" AND ", $where));

			// Execute query, return a single result
			return call_user_func_array("static::fetchSingle", $values) ? true : false;
		}

		/*
			Function: fetch
				Equivalent to calling mysql_fetch_assoc on a query.
				If a query string is passed rather than a chained call it will return a single row after executing the query.

			Parameters:
				query - Optional, a query to execute before fetching
				parameters - Additional parameters to send to the query method

			Returns:
				A row from the active query (or false if no more rows exist)
		*/

		function _local_fetch() {
			// Allow this to be called without calling query first
			$args = func_get_args();
			if (count($args)) {
				$query = call_user_func_array(array($this, "query"), $args);

				return $query->fetch();
			}

			// Chained call
			if (!is_object($this->ActiveQuery)) {
				trigger_error("SQL::fetch called on invalid query resource. The most likely cause is an invalid query call. Last error returned was: ".static::$ErrorLog[count(static::$ErrorLog) - 1], E_USER_ERROR);

				return false;
			} else {
				return $this->ActiveQuery->fetch_assoc();
			}
		}

		static function _static_fetch() {
			// Allow this to be called without calling query first
			$query = call_user_func_array("static::query", func_get_args());

			return $query->fetch();
		}

		/*
			Function: fetchAll
				Returns all remaining rows for the active query.
				If a query string is passed rather than a chained call it will return the results after executing the query.

			Parameters:
				query - Optional, a query to execute before fetching
				parameters - Additional parameters to send to the query method
			
			Returns:
				An array of rows from the active query.
		*/

		function _local_fetchAll() {
			// Allow this to be called without calling query first
			$args = func_get_args();
			if (count($args)) {
				$query = call_user_func_array(array($this, "query"), $args);

				return $query->fetchAll();
			}

			// Chained call
			if (!is_object($this->ActiveQuery)) {
				trigger_error("SQL::fetchAll called on invalid query resource. The most likely cause is an invalid query call. Last error returned was: ".static::$ErrorLog[count(static::$ErrorLog) - 1], E_USER_ERROR);

				return false;
			} else {
				$results = array();

				while ($result = $this->ActiveQuery->fetch_assoc()) {
					$results[] = $result;
				}

				return $results;
			}
		}

		static function _static_fetchAll() {
			$query = call_user_func_array("static::query", func_get_args());

			return $query->fetchAll();
		}

		/*
			Function: fetchAllSingle
				Equivalent to the fetchAll method but only the first column of each row is returned.

			Parameters:
				query - Optional, a query to execute before fetching
				parameters - Additional parameters to send to the query method
			
			Returns:
				An array of the first column of each row from the active query.

			See Also:
				<fetchAll>
		*/

		function _local_fetchAllSingle() {
			// Allow this to be called without calling query first
			$args = func_get_args();
			if (count($args)) {
				$query = call_user_func_array(array($this, "query"), $args);

				return $query->fetchAllSingle();
			}

			// Chained call
			if (!is_object($this->ActiveQuery)) {
				trigger_error("SQL::fetchAllSingle called on invalid query resource. The most likely cause is an invalid query call. Last error returned was: ".static::$ErrorLog[count(static::$ErrorLog) - 1], E_USER_ERROR);

				return false;
			} else {
				$results = array();

				while ($result = $this->ActiveQuery->fetch_assoc()) {
					$results[] = current($result);
				}

				return $results;
			}
		}

		static function _static_fetchAllSingle() {
			$query = call_user_func_array("static::query", func_get_args());

			return $query->fetchAllSingle();
		}

		/*
			Function: fetchSingle
				Equivalent to the fetch method but only the first column of the row is returned.
			
			Parameters:
				query - Optional, a query to execute before fetching
				parameters - Additional parameters to send to the query method

			Returns:
				The first column from the returned row.

			See Also:
				<fetch>
		*/

		function _local_fetchSingle() {
			// Allow this to be called without calling query first
			$args = func_get_args();
			if (count($args)) {
				$query = call_user_func_array(array($this, "query"), $args);

				return $query->fetchSingle();
			}

			// Chained call
			if (!is_object($this->ActiveQuery)) {
				trigger_error("SQL::fetchSingle called on invalid query resource. The most likely cause is an invalid query call. Last error returned was: ".static::$ErrorLog[count(static::$ErrorLog) - 1], E_USER_ERROR);

				return false;
			} else {
				$result = $this->ActiveQuery->fetch_assoc();

				return is_array($result) ? current($result) : false;
			}
		}

		static function _static_fetchSingle() {
			$query = call_user_func_array("static::query", func_get_args());

			return $query->fetchSingle();
		}

		/*
			Function: insert
				Inserts a row into the database and returns the primary key

			Parameters:
				table - The table to insert a row into
				values - An associative array of columns and values (i.e. "column" => "value")

			Returns:
				Primary key of the inserted row
		*/

		static function insert($table, $values) {
			if (!is_array($values) || !count($values)) {
				trigger_error("SQL::inserts expects a non-empty array as its second parameter");

				return false;
			}

			$columns = array();
			$vals = array();
			foreach ($values as $column => $value) {
				$columns[] = "`$column`";

				if (is_null($value)) {
					$vals[] = "NULL";
				} elseif ($value === "NOW()") {
					$vals[] = $value;
				} else {
					$vals[] = "'".static::escape($value)."'";
				}
			}
			
			$query_response = static::query("INSERT INTO `$table` (".implode(",", $columns).") VALUES (".implode(",", $vals).")");
			$id = $query_response->insertID();

			return $id ? $id : $query_response->ActiveQuery;
		}

		/*
			Function: insertID
				Equivalent to calling mysql_insert_id.

			Returns:
				The primary key for the most recently inserted row.
		*/

		static function insertID() {
			if (static::$WriteConnection && static::$WriteConnection !== "disconnected") {
				return static::$WriteConnection->insert_id;
			} else {
				return static::$Connection->insert_id;
			}
		}

		/*
			Function: nextColumnDefinition
				Return the next SQL name definition from a string.

			Parameters:
				string - A string with the name definition being terminated by a single `

			Returns:
				A string.
		*/

		static function nextColumnDefinition($string) {
			$key_name = "";
			$i = 0;
			$found_key = false;
			// Apparently we can have a backtick ` in a column name... ugh.
			while (!$found_key && $i < strlen($string)) {
				$char = substr($string, $i, 1);
				$second_char = substr($string, $i + 1, 1);
				if ($char != "`" || $second_char == "`") {
					$key_name .= $char;
					if ($char == "`") { // Skip the next one, this was just an escape character.
						$i++;
					}
				} else {
					$found_key = true;
				}
				$i++;
			}

			return $key_name;
		}

		/*
			Function: prepareData
				Processes form data into values understandable by the MySQL table.

			Parameters:
				table - The table to prepare data for
				data - Array of key->value pairs
				existing_description - If the table has already been described, pass it in instead of making prepareData do it again. (defaults to false)

			Returns:
				Array of data safe for MySQL.
		*/

		static function prepareData($table, $data, $existing_description = false) {
			// Setup column info
			$table_description = $existing_description ?: static::describeTable($table);
			$columns = $table_description["columns"];

			foreach ($data as $key => $val) {
				// If the column doesn't exist, drop from the data arra
				if (!isset($columns[$key])) {
					unset($data[$key]);
				} else {
					$allow_null = $columns[$key]["allow_null"];
					$type = $columns[$key]["type"];

					// Sanitize Integers
					if ($type == "tinyint" || $type == "smallint" || $type == "mediumint" || $type == "int" || $type == "bigint") {
						if ($allow_null == "YES" && ($val === null || $val === false || $val === "" || $val === "NULL")) {
							$data[$key] = null;
						} else {
							$data[$key] = intval(str_replace(array(",", "$"), "", $val));
						}
					}

					// Sanitize Floats
					if ($type == "float" || $type == "double" || $type == "decimal") {
						if ($allow_null == "YES" && ($val === null || $val === false || $val === "" || $val === "NULL")) {
							$data[$key] = null;
						} else {
							$data[$key] = floatval(str_replace(array(",", "$"), "", $val));
						}
					}

					// Sanitize Date/Times
					if ($type == "datetime" || $type == "timestamp") {
						if (substr($val, 0, 3) == "NOW") {
							$data[$key] = "NOW()";
						} elseif ((!$val || $val === "NULL") && $allow_null == "YES") {
							$data[$key] = null;
						} elseif ($val == "") {
							$data[$key] = "0000-00-00 00:00:00";
						} else {
							$data[$key] = date("Y-m-d H:i:s", strtotime($val));
						}
					}

					// Sanitize Dates/Years
					if ($type == "date" || $type == "year") {
						if (substr($val, 0, 3) == "NOW") {
							$data[$key] = "NOW()";
						} elseif ((!$val || $val === "NULL") && $allow_null == "YES") {
							$data[$key] = null;
						} elseif (!$val) {
							$data[$key] = "0000-00-00";
						} else {
							$data[$key] = date("Y-m-d", strtotime($val));
						}
					}

					// Sanitize Times
					if ($type == "time") {
						if (substr($val, 0, 3) == "NOW") {
							$data[$key] = "NOW()";
						} elseif ((!$val || $val === "NULL") && $allow_null == "YES") {
							$data[$key] = null;
						} elseif (!$val) {
							$data[$key] = "00:00:00";
						} else {
							$data[$key] = date("H:i:s", strtotime($val));
						}
					}
				}
			}

			return $data;
		}

		/*
			Function: query
				Queries the MySQL server(s).
				If you pass additional parameters "?" characters in your query statement
				  will be replaced with escaped values in the order they are found.

			Parameters:
				query - The MYSQL query to execute
				... - Optional parameters that will invoke MySQL prepared statement fills

			Returns:
				Another instance of BigTree\SQL for chaining fetch, fetchAll, insertID, or rows methods.
		*/

		static function query($query) {
			global $bigtree;

			// Setup our read connection if it disconnected for some reason
			$connection = (static::$Connection && static::$Connection !== "disconnected") ? static::$Connection : static::connect("Connection", "db");

			// If we have a separate write host, let's find out if we're writing and use it if so
			if (isset($bigtree["config"]["db_write"]) && $bigtree["config"]["db_write"]["host"]) {
				$commands = explode(" ", $query);
				$fc = strtolower($commands[0]);
				if ($fc == "create" || $fc == "drop" || $fc == "insert" || $fc == "update" || $fc == "set" || $fc == "grant" || $fc == "flush" || $fc == "delete" || $fc == "alter" || $fc == "load" || $fc == "optimize" || $fc == "repair" || $fc == "replace" || $fc == "lock" || $fc == "restore" || $fc == "rollback" || $fc == "revoke" || $fc == "truncate" || $fc == "unlock") {
					$connection = (static::$WriteConnection && static::$WriteConnection !== "disconnected") ? static::$WriteConnection : static::connect("WriteConnection", "db_write");
				}
			}

			// If we only have a single argument we're not doing a prepared statement thing
			$args = func_get_args();

			if (count($args) == 1) {
				$query_response = $connection->query($query);
			} else {
				// Check argument and ? count to trigger warnings
				$wildcard_count = substr_count($query, "?");
				
				if ($wildcard_count != (count($args) - 1)) {
					throw new \Exception("SQL::query error - wildcard and argument count do not match ($wildcard_count '?' found, ".(count($args) - 1)." arguments provided)");
				}

				// Do the replacements and escapes
				$x = 1;
				$offset = 0;
				while (($position = strpos($query, "?", $offset)) !== false) {
					// Allow for these reserved keywords to be let through unescaped
					if (is_null($args[$x])) {
						$replacement = "NULL";
					} elseif ($args[$x] === "NOW()") {
						$replacement = $args[$x];
					} else {
						$replacement = "'".static::escape($args[$x])."'";
					}

					// If the replacement contained a ? we don't want it to be replaced, so start after the replacement
					$offset = strlen($replacement) + $position;

					// Replace
					$query = substr($query, 0, $position).$replacement.substr($query, $position + 1);

					// Increment argument
					$x++;
				}

				// Return the query object
				$query_response = $connection->query($query);
			}

			// Log errors
			if (!is_object($query_response) && $connection->error) {
				static::$ErrorLog[] = $connection->error;
			}

			// Debug should log queries
			if ($bigtree["config"]["debug"]) {
				static::$QueryLog[] = $query;
			}

			return new SQL($query_response);
		}

		/*
			Function: rows
				Equivalent to calling mysql_num_rows.

			Parameters:
				query - Optional returned query object (defaults to using chained method)

			Returns:
				Number of rows for the active query.
		*/

		function _local_rows() {
			return $this->ActiveQuery->num_rows;
		}

		static function _static_rows($query) {
			return $query->ActiveQuery->num_rows;
		}

		/*
			Function: tableExists
				Determines whether a SQL table exists.

			Parameters:
				table - The table name.

			Returns:
				true if table exists, otherwise false.
		*/

		static function tableExists($table) {
			$rows = static::query("SHOW TABLES LIKE ?", $table)->rows();
			if ($rows) {
				return true;
			}

			return false;
		}

		/*
			Function: unique
				Retrieves a unique version of a given field for a table.
				Appends trailing numbers to the string until a unique version is found (i.e. value-2)
				Useful for creating unique routes.

			Parameters:
				table - Table to search
				field - Field that must be unique
				value - Value to check
				id - An optional ID for a record to disregard (either a single value for checking "id" column or key/value pair)
				inverse - Set to true to force the id column to true rather than false

			Returns:
				Unique version of value.
		*/

		static function unique($table, $field, $value, $id = false, $inverse = false) {
			$original_value = $value;
			$count = 1;

			// If we're checking against an ID
			if ($id !== false) {

				// Allow for passing array("column" => "value")
				if (is_array($id)) {
					list($id_column) = array_keys($id);
					$id_value = current($id);
					// Allow for passing "value"
				} else {
					$id_column = "id";
					$id_value = $id;
				}

				// If inverse, switch ID requirement to be = rather than !=
				if ($inverse) {
					$query = "SELECT COUNT(*) FROM `$table` WHERE `$field` = ? AND `$id_column` = ?";
				} else {
					$query = "SELECT COUNT(*) FROM `$table` WHERE `$field` = ? AND `$id_column` != ?";
				}

				while (static::fetchSingle($query, $value, $id_value)) {
					$count++;
					$value = $original_value."-$count";
				}

				// Checking the whole table
			} else {
				while (static::fetchSingle("SELECT COUNT(*) FROM `$table` WHERE `$field` = ?", $value)) {
					$count++;
					$value = $original_value."-$count";
				}
			}

			return $value;
		}

		/*
			Function: update
				Updates a row in the database

			Parameters:
				table - The table to insert a row into
				id - The ID of the row to update (or an associate array of key/value pairs to match)
				values - An associative array of columns and values (i.e. "column" => "value")

			Returns:
				true if successful (even if no rows match)
		*/

		static function update($table, $id, $values) {
			if (!is_array($values) || !count($values)) {
				trigger_error("SQL::update expects a non-empty array as its third parameter");

				return false;
			}

			// Setup our array to implode into a query
			$set = array();
			foreach ($values as $column => $value) {
				$set[] = "`$column` = ?";
			}

			$where = array();
			// If the ID is an associative array we match based on the given columns
			if (is_array($id)) {
				foreach ($id as $column => $value) {
					$where[] = "`$column` = ?";
					array_push($values, $value);
				}
				// Otherwise default to id
			} else {
				$where[] = "`id` = ?";
				array_push($values, $id);
			}

			// Add the query and the id parameter into the function parameters
			array_unshift($values, "UPDATE `$table` SET ".implode(", ", $set)." WHERE ".implode(" AND ", $where));

			// Call BigTree\SQL::query
			$response = call_user_func_array("static::query", $values);

			return $response->ActiveQuery ? true : false;
		}

	}
	