<?php

namespace b8;

use b8\Database;
use b8\Database\Query;
use b8\Exception\HttpException;

abstract class Store
{
    protected $modelName = null;
    protected $tableName = null;
    protected $primaryKey = null;


    abstract public function getByPrimaryKey($key, $useConnection = 'read');

    public function save(Model $obj, $saveAllColumns = false)
    {
        if (!isset($this->primaryKey)) {
            throw new HttpException\BadRequestException('Save not implemented for this store.');
        }

        if (!($obj instanceof $this->modelName)) {
            throw new HttpException\BadRequestException(get_class($obj) . ' is an invalid model type for this store.');
        }

        $data = $obj->getDataArray();

        if (isset($data[$this->primaryKey])) {
            $rtn = $this->saveByUpdate($obj, $saveAllColumns);
        } else {
            $rtn = $this->saveByInsert($obj, $saveAllColumns);
        }

        return $rtn;
    }

    public function saveByUpdate(Model $obj, $saveAllColumns = false)
    {
        $rtn = null;
        $data = $obj->getDataArray();
        $modified = ($saveAllColumns) ? array_keys($data) : $obj->getModified();

        $updates = array();
        $update_params = array();
        foreach ($modified as $key) {
            $updates[] = $key . ' = :' . $key;
            $update_params[] = array($key, $data[$key]);
        }

        if (count($updates)) {
            $qs = 'UPDATE ' . $this->tableName . '
											SET ' . implode(', ', $updates) . '
											WHERE ' . $this->primaryKey . ' = :primaryKey';
            $q = Database::getConnection('write')->prepare($qs);

            foreach ($update_params as $update_param) {
                $q->bindValue(':' . $update_param[0], $update_param[1]);
            }

            $q->bindValue(':primaryKey', $data[$this->primaryKey]);
            $q->execute();

            $rtn = $this->getByPrimaryKey($data[$this->primaryKey], 'write');
        } else {
            $rtn = $obj;
        }

        return $rtn;
    }

    public function saveByInsert(Model $obj, $saveAllColumns = false)
    {
        $rtn = null;
        $data = $obj->getDataArray();
        $modified = ($saveAllColumns) ? array_keys($data) : $obj->getModified();

        $cols = array();
        $values = array();
        $qParams = array();
        foreach ($modified as $key) {
            $cols[] = $key;
            $values[] = ':' . $key;
            $qParams[':' . $key] = $data[$key];
        }

        if (count($cols)) {
            $colString = implode(', ', $cols);
            $valString = implode(', ', $values);

            $qs = 'INSERT INTO ' . $this->tableName . ' (' . $colString . ') VALUES (' . $valString . ')';
            $q = Database::getConnection('write')->prepare($qs);

            if ($q->execute($qParams)) {
                $id = !empty($data[$this->primaryKey]) ? $data[$this->primaryKey] : Database::getConnection(
                    'write'
                )->lastInsertId();
                $rtn = $this->getByPrimaryKey($id, 'write');
            }
        }

        return $rtn;
    }

    public function delete(Model $obj)
    {
        if (!isset($this->primaryKey)) {
            throw new HttpException\BadRequestException('Delete not implemented for this store.');
        }

        if (!($obj instanceof $this->modelName)) {
            throw new HttpException\BadRequestException(get_class($obj) . ' is an invalid model type for this store.');
        }

        $data = $obj->getDataArray();

        $q = Database::getConnection('write')->prepare(
            'DELETE FROM ' . $this->tableName . ' WHERE ' . $this->primaryKey . ' = :primaryKey'
        );
        $q->bindValue(':primaryKey', $data[$this->primaryKey]);
        $q->execute();

        return true;
    }

    /**
     *
     */
    protected function fieldCheck($field)
    {
        if (empty($field)) {
            throw new HttpException('You cannot have an empty field name.');
        }

        if (strpos($field, '.') === false) {
            return $this->tableName . '.' . $field;
        }

        return $field;
    }

    /**
     * @param Query $query
     * @param array $options
     */
    public function handleQueryOptions(Query &$query, array $options)
    {
        if (array_key_exists('limit', $options)) {
            $query->limit($options['limit']);
        }

        if (array_key_exists('offset', $options)) {
            $query->offset($options['offset']);
        }

        if (array_key_exists('order', $options)) {
            if (is_string($options['order'])) {
                $options['order'] = array($options['order']);
            }

            foreach ($options['order'] as $order) {
                $query->order($order[0], $order[1]);
            }
        }
    }
}
