<?php
/**
 * This class extends PDO for allowing the use of table prefix
 * when a database is shared by multiple applications.
 * 
 * In the SQL statements the table must be enclosed in braces for adding the prefix.
 * 
 */
class MLPDO extends PDO {
  private $m_prefix = "";

/**
 * Sets the table prefix. Must be called before doing any query.
 * 
 * @param string $prefix
 */
  public function setPrefix ($prefix){
    $this->m_prefix = $prefix;
  }

  public function showprefix (){
    return $this->m_prefix;
  }

  /**
   * This method is called by prepare, exec and query for automatically for
   * adding the prefix.
   * 
   * @param string $statement The SQL statement with the table enclosed in braces.
   * 
   * @return string The correct statement.
   */
  private function putPrefix ($statement){
    return preg_replace ('/\s\{([A-Za-z0-9-_]+)\}/', " " . $this->m_prefix . "$1", $statement);
  }


  /**
   * It's an overload of PDO prepare method. 
   * 
   * @param string $statement The SQL statement with the table enclosed in braces.
   * @param array $driver_options PDO driver options array
   * 
   */
  #[\ReturnTypeWillChange]
  public function prepare ($statement , $driver_options = array() ){
    $statement = $this->putPrefix ($statement);
    return parent::prepare ($statement, $driver_options);
  }

  /**
   * It's an overload of PDO exec method. 
   * 
   * @param string $statement The SQL statement with the table enclosed in braces.
   * 
   */
  #[\ReturnTypeWillChange]
  public function exec($statement)
    {
        $statement = $this->putPrefix($statement);
        return parent::exec($statement);
    }

  /**
   * It's an overload of PDO query method. 
   * 
   * @param string $statement The SQL statement with the table enclosed in braces.
   * @param ?int $fetchmode PDO fetch mode.
   * @param variadic $fetchmodeArgs
   * 
   */
  #[\ReturnTypeWillChange]
  public function query(string $statement, ?int $fetchmode = null, ...$fetchModeArgs)
    {
        $statement = $this->putPrefix($statement);

        if ($fetchmode !== null) {
            return parent::query($statement, $fetchmode, ...$fetchModeArgs);
        } else {
            return parent::query($statement);
        }
    }

  /**
   * This method is for debug purposes.
   * 
   * @param string $statement
   */
  public function showquery ($statement){
  
    return $this->putPrefix ($statement);
  
  }
}
