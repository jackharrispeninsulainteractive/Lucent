<?php

namespace Lucent;

use Lucent\Database\Dataset;
use ReflectionClass;

class ModelCollection
{

    private string $class;
    private array $where;
    private array $like;

    private int $limit;
    private int $offset;

    private array $cache;

    public function __construct($class){
        $this->class = $class;
        $this->where = [];
        $this->limit = 10;
        $this->offset = 0;
        $this->cache = [];
        $this->like = [];
        return $this;
    }

    public function where(string $column, string $value): ModelCollection
    {

        $this->where[$column] = $value;

        return $this;
    }

    public function like(string $column, string $value) : ModelCollection
    {
        $this->like[$column] = $value;
        return $this;
    }

    public function limit($count) : ModelCollection
    {
        $this->limit = $count;
        return $this;
    }

    public function offset($count) : ModelCollection
    {
        $this->offset = $count;
        return $this;
    }

    public function get(): array
    {
        $query = $this->buildQuery();

        if(array_key_exists($query,$this->cache)){
            return $this->cache[$query];
        }

        $results = Database::fetchAll($query);
        $instances = [];
        $class = new ReflectionClass($this->class);

        foreach ($results as $result){
            array_push($instances,$class->newInstance(new Dataset($result)));
        }

        $this->cache[$query] = $instances;
        return $instances;
    }

    public function getFirst()
    {
        $this->limit = 1;
        $data = Database::fetch($this->buildQuery());

        if(count($data) > 0) {
            $class = new ReflectionClass($this->class);
            return $class->newInstance(new Dataset($data));
        }else{
            return null;
        }
    }

    public function getClass()
    {
        return $this->class;
    }

    public function collection() : ModelCollection{
        return $this;
    }

    public function count() : int
    {
        $array = explode("\\", $this->class);
        $className = end($array);

        $query = "SELECT count(*) from " . $className;

        if(count($this->where) >0){
            $query .= " WHERE ";

            foreach ($this->where as $key => $value){
                $query .= $key."='".$value."' AND ";
            }

            $query =  substr($query,0,strlen($query)-5);
        }

        if(count($this->like) >0){
            $query .= " AND ";

            foreach ($this->like as $key => $value){
                $query .= $key." LIKE '%".$value."%' AND ";
            }

            $query =  substr($query,0,strlen($query)-5);
        }

        return (int)Database::fetch($query)["count(*)"];
    }

    private function buildQuery(): string
    {
        $array = explode("\\", $this->class);
        $className = end($array);

        $query = "SELECT * from ".$className;

        if(count($this->where) >0){
            $query .= " WHERE ";

            foreach ($this->where as $key => $value){
                $query .= $key."='".$value."' AND ";
            }

            $query =  substr($query,0,strlen($query)-5);
        }

        if(count($this->like) >0){
            $query .= " AND ";

            foreach ($this->like as $key => $value){
                $query .= $key." LIKE '%".$value."%' AND ";
            }

            $query =  substr($query,0,strlen($query)-5);
        }

        $query .= " LIMIT ".$this->limit;

        if($this->offset != 0) {
            $query .= " OFFSET " . $this->offset;
        }

        return $query;
    }

}