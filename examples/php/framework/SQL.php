<?php namespace framework;

use ValueError;

/*
 * Connect to mySQL-Server - Server data are stored in server.config.php
 * so this file can be updated without breaking anything.
 */

require_once("server.config.php");

// Default way of connecting with the mySQL database
//In case the connection failes the magic " or EXPRESSION" gets executed,
//with "die( MESSAGE )" to stop further execution.
/** @var \mysqli $sqlcon */
$sqlcon;

(function() {
    global $sqlcon;
    global $sqlcon_host;
    global $sqlcon_user;
    global $sqlcon_pass;
    global $sqlcon_dBas;

    $sqlcon = mysqli_connect($sqlcon_host, $sqlcon_user, $sqlcon_pass, $sqlcon_dBas);// or die("Error " . mysqli_error($sqlcon));
    //We want to tell mySQL that we're using UTF-8, since you might get stuff
    //like Umlaute and we want to write/read them correctly
    mysqli_set_charset($sqlcon, 'utf8');
    // synchronize timezones between php and mysql
    $now = new \DateTime();
    $mins = $now->getOffset() / 60;
    $sgn = ($mins < 0 ? -1 : 1);
    $mins = abs($mins);
    $hrs = floor($mins / 60);
    $mins -= $hrs * 60;
    $offset = sprintf('%+d:%02d', $hrs * $sgn, $mins);
    if ($sqlcon->query("SET time_zone='$offset'") === false) {
        echo "Failed to set db timezone to {$offset}";
    }
})();

interface SQLResult {
    public function failed(): bool;
    public function success(): bool;
    public function rows(): string|int;
}

class SQLQueryResult implements SQLResult
{
    public function __construct(
        public readonly \mysqli_result|false $mysqli_result
    ) { }

    public function __destruct()
    {
        if ($this->success()) {
            mysqli_free_result($this->mysqli_result);
        }
    }

    public function failed(): bool {
        return $this->mysqli_result === false;
    }
    public function success(): bool {
        return $this->mysqli_result !== false;
    }

    /**
     * This finction will try to return the number of result rows.
     * Normally mysqli_num_rows (or this function) is NOT used as it is 0 for unbuffered.
     * @return  string|int - The number of affected rows or -1 if not applicable
     */
    public function rows(): string|int
    {
        if ($this->failed()) {
            return -1;
        }
        return mysqli_num_rows($this->mysqli_result);
    }

    /**
     * Get the next row from the mySQL result set as associative array,
     * meaning that you can use the column name as index to receive data,
     * or NULL if the cursor is positioned beyond the last result.
     * while ( ( $row = getRow() ) != NULL )
     *   echo "ID = " . $row[ 'ID' ] ;
     * @return  array|null|false - The next row as array or NULL
     */
    public function getRow(): array|null|false
    {
        return mysqli_fetch_assoc($this->mysqli_result);
    }

    /**
     * Iterates and transforms all result rows given an optional mapper.
     * @template T - type mapped from sql row
     * @param callable(array):T $rowMapper   function to convert a row as associative array into a different representation
     * @return T[] - array of rows, transformed or as associative array if no mapper was specified
     */
    public function getAll(callable|null $rowMapper = null): array
    {
        $results = [];
        if ($rowMapper !== null) {
            while (($row = $this->getRow()) !== null) {
                $results[] = $rowMapper($row);
            }
        } else {
            while (($row = $this->getRow()) !== null) {
                $results[] = $row;
            }
        }
        return $results;
    }

    /**
     * Fetches and transforms one result row given an optional mapper.
     * @template T - type mapped from sql row
     * @param callable(array):T $rowMapper   function to convert the row as associative array into a different representation
     * @return T|null - transformed representation or associative array if no mapper was specified, false if no row was returned
     */
    public function getOne(callable|null $rowMapper = null): mixed
    {
        if ($rowMapper !== null) {
            if (($row = $this->getRow()) !== null) {
                return $rowMapper($row);
            }
        } else {
            if (($row = $this->getRow()) !== null) {
                return $row;
            }
        }
        return null;
    }
}

class SQLCommandResult implements SQLResult
{
    private int|string $affected_rows;

    public function __construct(
        public readonly bool $mysqli_result
    ) {
        global $sqlcon;
        if ($mysqli_result === true) {
            $this->affected_rows = mysqli_affected_rows($sqlcon);
        } else {
            $this->affected_rows = -1;
        }
    }

    public function failed(): bool {
        return $this->mysqli_result === false;
    }
    public function success(): bool {
        return $this->mysqli_result !== false;
    }

    /**
     * This finction will try to return the number of affected rows.
     * @return string|int - The number of affected rows or -1 if not applicable or error
     */
    public function rows(): string|int
    {
        return $this->affected_rows;
    }
}

class SQLInsertResult implements SQLResult
{
    public readonly int|string $insert_id;

    public function __construct(
        public readonly bool $mysqli_result,

    ) {
        global $sqlcon;
        if ($mysqli_result === true) {
            $this->insert_id = mysqli_insert_id($sqlcon);
        } else {
            $this->insert_id = 0;
        }
    }

    public function failed(): bool {
        return $this->mysqli_result === false;
    }
    public function success(): bool {
        return $this->mysqli_result !== false;
    }

    /**
     * This finction will try to return the number of affected rows.
     * @return string|int - The number of affected rows or -1 if not applicable or error
     */
    public function rows(): string|int
    {
        return -1;
    }
}

class SQL {
    /*
    * Following functions wrap the base functions to interact with a database.
    * These mainly exist because the original function names are hella long!
    * Design philosophy:
    *   - If you put a raw string, you know what you're doing.
    *   - If you use arrays, values get auto escaped. Column names are fixed up as good as possible.
    *   - If you need a raw value, you can box it in an array, and it won't get escaped.
    */


    /**
     * After the PHP script is done with all SQL-related code it is good practice
     * to close the database with mysqli_close. It's normaly no problem if you do
     * not close the database as it will be automatically closed after script
     * termination
     * @return  void - nothing
     */
    public static function done(): void
    {
        global $sqlcon;
        mysqli_close($sqlcon);
    }

    /**
     * A shortcut for mysqli_real_escape_string(link, string).
     * Nobody got time to type that out every time, right? ;D
     * EVERY INPUT A USER COULD POSSIBLY PUT INTO A QUERY SHOULD EITHER BE
     * ESCAPED OR (NUMERICS) TYPE-CHECKED WITH A CTYPE FUNCTION IN ORDER TO
     * PREVENT SQL-INJECTIONS!
     * @param   $string The data to be escaped
     * @return  ?string - The escaped string
     */
    public static function escape(?string $string): ?string
    {
        global $sqlcon;
        if (is_null($string) || is_numeric($string)) {
            return $string;
        }
        return mysqli_real_escape_string($sqlcon, $string);
    }

    /** @see \mysqli_begin_transaction */
    public static function begin(?string $name = null, bool $readOnly = false): bool
    {
        global $sqlcon;
        return mysqli_begin_transaction($sqlcon, $readOnly ? MYSQLI_TRANS_START_READ_ONLY : MYSQLI_TRANS_START_READ_WRITE, $name);
    }

    /** @see \mysqli_commit */
    public static function commit(?string $name = null): bool {
        global $sqlcon;
        return mysqli_commit($sqlcon, name: $name);
    }

    /** @see \mysqli_autocommit */
    public static function autocommit(bool $enable): bool
    {
        global $sqlcon;
        return mysqli_autocommit($sqlcon, $enable);
    }

    /** @see \mysqli_rollback */
    public static function rollback(?string $name = null): bool {
        global $sqlcon;
        return mysqli_rollback($sqlcon, name: $name);
    }

    /**
     * In case you want to perform a non-standart query you can just run a raw
     * sql query using this function to get the result in the global
     * $sqlresult. Depending on the type of query the Result-Type will vary,
     * see http://php.net/manual/en/mysqli.query.php
     * EVERY INPUT A USER COULD POSSIBLY PUT INTO A QUERY SHOULD EITHER BE
     * ESCAPED OR (NUMERICS) TYPE-CHECKED WITH A CTYPE FUNCTION IN ORDER TO
     * PREVENT SQL-INJECTIONS!
     * @param   $query  The query to be issued on the database.
     * @return  \mysqli_result|bool - The global result variable
     */
    public static function query(string $query): \mysqli_result|bool
    {
        global $sqlcon;
        return mysqli_query($sqlcon, $query);
    }

    /*
    * The next part declares convenience functions for every-day queries.
    * Most finction have a corresponding *SqlQuery* function to build the
    * query, before the query is being executed and the result returned.
    */

    /**
     * The most basic function in order to read data from a table.
     * Selects data using the Condition (= WHERE-clause) and additional
     * filters namely ORDER, LIMIT and OFFSET and retuns a result set
     * that can be proccessed with sqlGetRow();
     * Columns should always be enclosed in grave accents (`) in order
     * to prevent confisuion with other syntax elements.
     * If you do not care about a filter e.g. Condition (WHERE-clause) you can
     * skip it by passing NULL as argument. Example use:
     * sqlSelect( 'users', '`Name`, `ID`', NULL, '`ID` DESC', 1 );
     * if ( ( $row = sqlGetRow() ) != NULL )
     *   echo "{$row['Name']} is the newest Member with ID {$row['ID']}" ;
     *
     * For more information on the SELECT syntax see
     * https://dev.mysql.com/doc/refman/5.7/en/select.html
     * @param   $Table      The table name from which you want to read from.
     *          The prefix from the config file will automatically be added.
     * @param   $Columns    The name or list of column names to retrieve.
     *          The wildcard * for columns means all columns should be returned.
     *          If you want to pass a
     * @param   $Condition  If not NULL it will append WHERE $Condition (after columns)
     * @param   $Order      If not NULL it will append ORDER BY $Order (after condition)
     * @param   $Limit      If not NULL it will append LIMIT $Limit (after order)
     * @param   $Offset     If not NULL it will append OFFSET ".$Offset (after limit)
     * @param   $Join       If not NULL it will LEFT JOIN key ON val[0] = val[1] (before condition)
     * @return  SQLQueryResult - $sqlresult as SQL result set or NULL for no results or FALSE in case the query failed
     */
    public static function select(string $Table, string|array $Columns = "*", null|string|array $Condition = null, null|string $Order = null, null|string|int $Limit = null, null|string|int $Offset = null, null|array $Join = null): SQLQueryResult
    {
        $result = self::query(self::getSqlQuerySelect($Table, $Columns, $Condition, $Order, $Limit, $Offset, $Join) . ";");
        return new SQLQueryResult($result);
    }

    /**
     * Build a SELECT query, see sqlSelect()
     * Table names support "name AS alias", prefix will be applied (and backticks if missing).
     * Collumn names support table prefix, backticks will be applied if missing.
     * @param   $Table      The table name from which you want to read from.
     *          The prefix from the config file will automatically be added.
     * @param   $Columns    The name or list of column names to retrieve.
     *          The wildcard * for columns means all columns should be returned.
     * @param   $Condition  If not NULL it will append WHERE $Condition (after columns)
     * @param   $Order      If not NULL it will append ORDER BY $Order (after condition)
     * @param   $Limit      If not NULL it will append LIMIT $Limit (after order)
     * @param   $Offset     If not NULL it will append OFFSET ".$Offset (after limit)
     * @param   $Join       If not NULL it will LEFT JOIN tbls ON col1 = col2 (before condition)
     *          Tables are keys in the Array, Collumn associations are values in the Array.
     *          ['tbl2 as t2' => ['t1.a' , 't2.b'] ] <- backticks will be applied if missing.
     * @return  string - readied query string
     */
    public static function getSqlQuerySelect(string $Table, string|array $Columns = "*", null|string|array $Condition = null, null|string $Order = null, null|string|int $Limit = null, null|string|int $Offset = null, null|array $Join = null): string
    {
        // automatically correctly concatinate the array to be a string representation of the columns list
        if (is_array($Columns)) {
            $Columns = self::columnImplode($Columns);
        }
        // build the base query with minimum requirements
        $query = "SELECT " . $Columns . " FROM " . self::expandTableName($Table);
        // join clause construction is currently only interesting for select
        if ($Join != null) {
            $joinfmt = ' LEFT JOIN (' . implode(', ', array_map(self::expandTableName(...), array_keys($Join))) . ') ON ';
            $rules = [];
            foreach ($Join as $jtbl => $jcols) {
                if (is_array($jcols)) {
                    $rules[] = self::quoteColumn($jcols[0]) . ' = ' . self::quoteColumn($jcols[1]);
                } else {
                    $rules[] = (string)$jcols;
                }
            }
            if (!empty($rules)) {
                $query .= $joinfmt . '(' . implode(' AND ', $rules) . ')';
            }
        }
        // depending on further arguments append filters to the query
        if ($Condition !== null) {
            $part = (is_array($Condition) ? self::kvImplode($Condition, ' AND ', Selecting: true) : $Condition);
            if (!empty($part)) {
                $query .= " WHERE " . $part;
            }
        }
        if (!empty($Order)) {
            $query .= " ORDER BY " . $Order;
        }
        if (!empty($Limit)) {
            $query .= " LIMIT " . (string)$Limit;
        }
        if (!empty($Offset)) {
            $query .= " OFFSET " . (string)$Offset;
        }

        return $query;
    }

    /** Inserts a new row into the table.
     * Every column that has no default value or isn't auto increment has to
     * have a value assigned in $Data.
     * The keys are expected to be without spaces and to be NOT enclosed in
     * grave accents. See sqlKVImplode() for information as it's used for $Data.
     * Values are expected to be already escaped before calling this function.
     * @param   $Table  The table to insert a new row into.
     *          The prefix from the config file will automatically be added.
     * @param   $Data   An associative array with Key -> Value pairs. Dummy box Value into an array to prevent automatic escaping.
     * @param   $Ignore Ignore duplicate entries
     * @return  SQLInsertResult - The ID the row was inserted with by using mysqli_insert_id
     */
    public static function insert(string $Table, array $Data, bool $Ignore = false): SQLInsertResult
    {
        $sqlresult = self::query(self::getSqlQueryInsert($Table, $Data, $Ignore));
        return new SQLInsertResult($sqlresult);
    }

    /**
     * Build a INSERT query, see sqlInset()
     * @param   $Table  The table to insert a new row into.
     *          The prefix from the config file will automatically be added.
     * @param   $Data   An associative array with Key -> Value pairs. Dummy box Value into an array to prevent automatic escaping.
     * @param   $Ignore Insert ignore into
     * @return  string - readied query string
     */
    public static function getSqlQueryInsert(string $Table, array $Data, bool $Ignore = false): string
    {

        //prepare left part
        if ($Ignore) {
            $query = "INSERT IGNORE INTO ";
        } else {
            $query = "INSERT INTO ";
        }
        $query .= self::expandTableName($Table) . " ";
        //syntax is (KEY, KEY, ...) VALUES (VALUE, VALUE, ...)
        //so we set up both, the key and value list
        $sKeys = implode(', ', array_map(self::addBackticks(...), array_keys($Data)));
        $sValues = implode(', ', array_map(self::autoEscapeValue(...), array_values($Data)));
        $query .= "({$sKeys}) VALUES ({$sValues});";
        //finish up query with keys and values
        return $query;
        //query is now completed and ready to be returned
    }

    /**
     * Works exactly like sqlInsert but, if a row is found that contains the
     * values for columns given in $Unique the row won't be inserted.
     * For more information on $Data see sqlInsert.
     *
     * This HAS to be used if one of your unique columns are nullable, as
     * NULL is not NULL, meaning if one column is NULL, the unique constraint
     * fails, period.
     *
     * PLEASE TRY TO DEFINE UNIQUE KEYS IN YOUR TABLE STRUCTURE OVER USING THIS!
     * @param   $Table      The table to insert a new row into.
     *          The prefix from the config file will automatically be added.
     * @param   $Data       An associative array with Key -> Value pairs. Dummy box Value into an array to prevent automatic escaping.
     * @param   $Unique     An associative array with Key -> Value pairs. Dummy box Value into an array to prevent automatic escaping.
     *          if $Unique is a sequential array, it will be used to index $Data
     * @param   $Returning  An optional single column that shall be queried when
     *          a collision is detected. Through the nature of the soft insert
     *          query this has to be a second query.
     * @return  mixed - The ID the row was inserted with by using mysqli_insert_id
     *          (or, if specified, the Returning column on collision)
     */
    public static function softInsert(string $Table, array $Data, array $Unique, null|string $Returning = null): string|int
    {
        global $sqlcon;
        // expand $Unique to a dict over $Data
        if (!empty($Unique) && array_is_list($Unique)) {
            $tmp = [];
            foreach ($Unique as $key) {
                if (array_key_exists($key, $Data)) {
                    $tmp[$key] = $Data[$key];
                }
            }
            $Unique = $tmp;
        }
        // prep query
        $query = 'INSERT INTO ' . self::expandTableName($Table) . ' ';
        $sKeys = implode(', ', array_map(self::addBackticks(...), array_keys($Data)));
        $sValues = implode(', ', array_map(self::autoEscapeValue(...), array_values($Data)));
        $query .= "({$sKeys}) SELECT {$sValues} FROM DUAL WHERE NOT EXISTS (" .
                    self::getSqlQuerySelect($Table, '*', $Unique) . ' LIMIT 1)';
        // and query it
        if (self::query($query) !== false) {
            return mysqli_insert_id($sqlcon);
        } elseif ($Returning !== null) {
            // read id column if query failed
            $result = self::select($Table, $Returning, $Unique);
            if ($result->success()) {
                return $result->getOne()[$Returning] ?? 0;
            }
        }
        return 0;
    }

    /**
     * Function that writes the content of $Data in every row matching the
     * condition (= WHERE-clause) and filters for the given table.
     * The keys are expected to be without spaces and to be NOT enclosed in
     * grave accents. See sqlKVImplode() for information as it's used for $Data.
     * If you do not care about a filter e.g. Condition (WHERE-clause) you can
     * skip it by passing NULL as argument. Example:
     * if (sqlUpdate('user', array('`balance`' => '`balance`+50'), "`Name`='Jack'") !== FALSE)
     *   echo "Jack's balance was increased by 50";
     * @param   $Table  The table to insert a new row into.
     *          The prefix from the config file will automatically be added.
     * @param   $Data   An associative array with Key -> Value pairs. Dummy box Value into an array to prevent automatic escaping.
     * @param   $Condition  If not NULL it will be appended like $query." WHERE ".$Condition
     * @param   $Order      If not NULL it will be appended like $query." ORDER BY ".$Order
     * @param   $Limit      If not NULL it will be appended like $query." LIMIT ".$Order
     * @return  SQLCommandResult - should be TRUE on success, FALSE otherwise
     */
    public static function update(string $Table, array|string $Data, null|string|array $Condition = null, null|string $Order = null, null|string|int $Limit = null): SQLCommandResult
    {
        $sqlresult = self::query(self::getSqlQueryUpdate($Table, $Data, $Condition, $Order, $Limit));
        return new SQLCommandResult($sqlresult);
    }

    /**
     * Build UPDATE query, see sqlUpdate()
     * @param   $Table  The table to insert a new row into.
     *          The prefix from the config file will automatically be added.
     * @param   $Data   An associative array with Key -> Value pairs. Dummy box Value into an array to prevent automatic escaping.
     * @param   $Condition  If not NULL it will be appended like $query." WHERE ".$Condition
     * @param   $Order      If not NULL it will be appended like $query." ORDER BY ".$Order
     * @param   $Limit      If not NULL it will be appended like $query." LIMIT ".$Order
     * @return  string - readied query string
     */
    public static function getSqlQueryUpdate(string $Table, array|string $Data, null|string|array $Condition = null, null|string $Order = null, null|string|int $Limit = null): string
    {
        $query = "UPDATE " . ($Table !== null ? self::expandTableName($Table) . " SET " : "");
        $query .= self::kvImplode($Data, ', ', Selecting: false);
        if ($Condition !== null) {
            $query .= " WHERE " . self::kvImplode($Condition, ' AND ', Selecting: true);
        }
        if ($Order !== null) {
            $query .= " ORDER BY " . $Order;
        }
        if ($Limit !== null) {
            $query .= " LIMIT " . (string)$Limit;
        }

        return $query;
    }

    /** Implements INSER * ON DUPLICATE KEY UPDATE *. Collisions are defined by UNIQUE and PRIMARY keys.
     * @param $Table  The table to insert or update data on.
     *        The prefix from the config file will automatically be added.
     * @param $Data   An associative array with Key -> Value paris. Dummy box Value into an array to prevent automatic escaping.
     * @param $Volatile  Array of colum names that will be updated on collision. Leave null for all.
     * @return SQLCommandResult
     */
    public static function upsert(string $Table, array|string $Data, null|array $Volatile = null): SQLCommandResult
    {
        $sqlresult = self::query(self::getSqlQueryUpsert($Table, $Data, $Volatile));
        return new SQLCommandResult($sqlresult);
    }

    public static function getSqlQueryUpsert(string $Table, array|string $Data, null|array $Volatile = null): string
    {
        //prepare left part
        $query = "INSERT INTO " . self::expandTableName($Table) . " ";
        //syntax is (KEY, KEY, ...) VALUES (VALUE, VALUE, ...)
        //so we set up both, the key and value list
        $sKeys = implode(', ', array_map(self::addBackticks(...), array_keys($Data)));
        $sValues = implode(', ', array_map(self::autoEscapeValue(...), array_values($Data)));
        //create the filtered data used for the upsert statement
        if ($Volatile === null) {
            $upsert = $Data;
        } else {
            $upsert = [];
            foreach ($Volatile as $key) {
                $upsert[$key] = $Data[$key];
            }
        }
        $query .= "({$sKeys}) VALUES ({$sValues}) ON DUPLICATE KEY UPDATE " . self::kvImplode($upsert, ', ', Selecting: false);
        //finish up query with keys and values
        return $query;
        //query is now completed and ready to be returned
    }


    /**
     * Works similar to the REPLACE statement but does not require the
     * column that's supposed to hold unique data to be primary key.
     * Example: Tables usually have a int ID primary key auto increment column.
     * If you want any EMail to be only used once to be registered in you
     * mailing list REPLACE would not work. With this function you can specify
     * the $Key column to be "EMail", so maybe only the clients name is being
     * updated by this function.
     * For more information on the $Data, see sqlInsert
     * THIS FUNCTION ISSUES TWO QUERIES AS TO LIMITATIONS IN MYSQL
     * @param   $Table  The table to insert a new row into.
     *          The prefix from the config file will automatically be added.
     * @param   $Key    The name of the column to be treated as unique key.
     * @param   $Data   An associative array with Key -> Value pairs. Dummy box Value into an array to prevent automatic escaping.
     * @return  SQLCommandResult|SQLInsertResult - should be TRUE on success, FALSE otherwise
     */
    public static function replace(string $Table, string $Key, array $Data): SQLCommandResult|SQLInsertResult
    {
        global $sqlcon;
        // Try to get rows, where the $Key column equals the $Key value in
        // $Data (as that's supposed to be unique)
        $pquery = "SELECT * FROM " . self::expandTableName($Table) . " WHERE " . $Key . "=" . self::autoEscapeValue($Data[$Key]) . " LIMIT 1";
        $privateresult = self::query($pquery) or die("Error " . mysqli_error($sqlcon) . " r");
        // If we have results, ther's already a row with such key->value
        // combination, so we update that
        if ($privateresult && mysqli_num_rows($privateresult) > 0) {
            mysqli_free_result($privateresult);
            return self::update($Table, $Data, $Key . "=" . self::autoEscapeValue($Data[$Key]), null, null);
        } else {
            // In case there was no result we can insert a row without creating
            // A duplicate for the $Key column
            return self::insert($Table, $Data);
        }
    }

    /**
     * This function will delete all rows from the selected table when the condition
     * is met. To empty a table it's better to use a TRUNCATE query.
     * @param   $Table  The table to delete rows from.
     *          The prefix from the config file will automatically be added.
     * @param   $Condition  If not NULL it will be appended like $query." WHERE ".$Condition
     * @param   $Limit      If not NULL it will be appended like $query." LIMIT ".$Order
     * @return  SQLCommandResult - see the SQL documentations on DELETE FROM
     */
    public static function delete(string $Table, array|string $Condition, null|string|int $Limit = null): SQLCommandResult
    {
        $query = "DELETE FROM " . self::expandTableName($Table);
        if ($Condition !== null) {
            $query .= " WHERE " . self::kvImplode($Condition, ' AND ', Selecting: true);
        }
        if ($Limit !== null) {
            $query .= " LIMIT " . (string)$Limit;
        }
        //And query it
        $sqlresult = self::query($query);
        return new SQLCommandResult($sqlresult);
    }

    /**
     * Helpers down below
     */

    /**
     * Adds backticks if $v does not start with a backtick, such that
     * a -> `a`
     * `a` -> `a`
     * `a -> `a  -- probably an error
     */
    protected static function addBackticks(string $v): string
    {
        if (str_starts_with($v, '`')) {
            return $v;
        }
        return "`$v`";
    }

    /**
     * Quotes column name parts where needed, table names are not prefixed, 'as' is not supported.
     * Intended for e.g. sqlSelect where Condition is on Joined/Aliased columns, so table prefixed are not applied.
     * a.b -> `a`.`b`
     * a.* -> `a`.*
     */
    protected static function quoteColumn(string $name): string
    {
        return implode('.', array_map(fn($x)=> (($x == '*') ? $x : self::addBackticks($x)), explode('.', $name)));
    }

    /** handles a table definition, adding the global prefix and quoting. aliasing is supported with 't AS a' */
    protected static function expandTableName(string $tbl): string
    {
        global $sqltp;
        if (($at = stripos($tbl, ' AS ')) !== false) {
            return "`$sqltp" . substr($tbl, 0, $at) . "` AS " . self::addBackticks(substr($tbl, $at + 4));
        } else {
            return "`{$sqltp}{$tbl}`";
        }
    }

    /**
     * Convenience function to implode associative $Data using a given $Concatinator
     * in SQL manner expecting the keys to be column names formatting a KV-pair like
     *  `KEY`='VALUE'  if VALUE contains ` or  `KEY`=VALUE  otherwise.
     * @param   $Data           An associative array containing the key->value mappings
     * @param   $Concatinator   The string to use to concatinate the KV-pair strings
     * @param   $Selecting      If true, NULL values are formatted `key` IS NULL because NULL != NULL
     * @return  string - representation that can be used in many queries
     */
    protected static function kvImplode(array|string $Data, string $Concatinator, bool $Selecting = true): string
    {
        if (!is_array($Data)) {
            return $Data;
        }
        $query = [];
        foreach ($Data as $k => $v) {
            $ek = ((str_starts_with($k, '`')) ? $k : self::quoteColumn($k));
            if ($v == null && $Selecting) {
                $query[] = "$ek IS NULL";
            } else {
                $ev = self::autoEscapeValue($v);
                $query[] = "$ek=$ev";
            }
        }
        return implode($Concatinator, $query);
    }

    /** Implode items, putting them in back-ticks for sql columns, if not already in backticks.
     * If items is associative, key=>value will be mapped to "`value` as key" so you get the keys back.
     * column names can be prefixed with database or table name.
     * If $Items is not an array it's assumed to be already prepared and returned as is.
     */
    protected static function columnImplode(string|array $Items): string
    {
        if (!is_array($Items)) {
            return $Items;
        }
        $value = [];
        if (array_is_list($Items)) {
            foreach ($Items as $i) {
                $value[] = self::addBackticks($i);
            }
        } else {
            foreach ($Items as $k => $v) {
                $ev = self::quoteColumn($v);
                $ek = self::addBackticks($k);
                $value[] = "{$ev} AS {$ek}";
            }
        }
        return implode(', ', $value);
    }

    /**
     * Automatically ''SQL::escape($v)'' value if it's a string. numbers and NULL remain as is.
     * Escape mechanism to write raw SQL values is to box them in an array like ['UUID()']
     */
    protected static function autoEscapeValue(null|string|array $Value): string
    {
        if (is_array($Value)) {
            if (array_is_list($Value) && count($Value) == 1) {
                return (string)($Value[0]);
            } else {
                throw new ValueError('Unescaped Value Syntax requires array with single value');
            }
        } elseif ($Value === null) {
            return 'NULL';
        } elseif (!empty($Value) && is_numeric($Value)) {
            return (string)$Value;
        } else {
            return "'".self::escape($Value)."'";
        }
    }

}
