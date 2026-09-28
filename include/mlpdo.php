<?php
class MLPDO extends PDO {
  private $m_prefix = "";

  public function setPrefix ($prefix){
    $this->m_prefix = $prefix;
  }

  public function showprefix (){
    return $this->m_prefix;
  }
  private function putPrefix ($statement){
    return preg_replace ('/\s\{([A-Za-z0-9-_]+)\}/', " " . $this->m_prefix . "$1", $statement);
  }

  #[\ReturnTypeWillChange]
  public function prepare ($statement , $driver_options = array() ){
    $statement = $this->putPrefix ($statement);
    return parent::prepare ($statement, $driver_options);
  }

  #[\ReturnTypeWillChange]
  public function exec($statement)
    {
        $statement = $this->putPrefix($statement);
        return parent::exec($statement);
    }


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
  public function showquery ($statement){
  
    return $this->putPrefix ($statement);
  
  }
}
