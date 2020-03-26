<?php

namespace cebe\yii2openapi\generator\helpers;

use yii\base\Component;
use yii\db\ColumnSchema;
use yii\db\Connection;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use yii\helpers\VarDumper;

class DatabaseDiff extends Component
{
    /**
     * @var string|array|Connection
     */
    public $db = 'db';

    public function init()
    {
        parent::init();
        $this->db = Instance::ensure($this->db, Connection::class);


    }

    public function diffTable($tableName, $columns)
    {
        $schema = $this->db->getTableSchema($tableName, true);
        if ($schema === null) {
            // create table
            $codeColumns = VarDumper::export(ArrayHelper::map($columns, 'dbName', function($c) {
                return $this->columnToDbType($this->attributeToColumnSchema($c));
            }));
            $upCode = str_replace("\n", "\n        ", "        \$this->createTable('$tableName', $codeColumns);");
            $downCode = "        \$this->dropTable('$tableName');";
            return [$upCode, $downCode];
        }

        $upCode = [];
        $downCode = [];

        // compare existing columns with expected columns
        $wantNames = array_keys($columns);
        $haveNames = $schema->columnNames;
        sort($wantNames);
        sort($haveNames);
        $missingDiff = array_diff($wantNames, $haveNames);
        $unknownDiff = array_diff($haveNames, $wantNames);
        foreach($missingDiff as $missingColumn) {
            $upCode[] = "\$this->addColumn('$tableName', '$missingColumn', '{$this->columnToDbType($this->attributeToColumnSchema($columns[$missingColumn]))}');";
            $downCode[] = "\$this->dropColumn('$tableName', '$missingColumn');";
        }
        foreach($unknownDiff as $unknownColumn) {
            $upCode[] = "\$this->dropColumn('$tableName', '$unknownColumn');";
            $oldDbType = $this->columnToDbType($schema->columns[$unknownColumn]);
            $downCode[] = "\$this->addColumn('$tableName', '$unknownColumn', '$oldDbType');";
        }

        // compare desired type with existing type
        foreach($schema->columns as $columnName => $currentColumnSchema) {
            if (!isset($columns[$columnName])) {
                continue;
            }
            $desiredColumnSchema = $this->attributeToColumnSchema($columns[$columnName]);
            switch (true) {
                case $desiredColumnSchema->dbType === 'pk':
                case $desiredColumnSchema->dbType === 'bigpk':
                    // do not adjust existing primary keys
                    break;
                case $desiredColumnSchema->dbType !== $currentColumnSchema->dbType:
                case $desiredColumnSchema->allowNull != $currentColumnSchema->allowNull:
                    $upCode[] = "\$this->alterColumn('$tableName', '$columnName', '{$this->columnToDbType($desiredColumnSchema)}');";
                    $downCode[] = "\$this->alterColumn('$tableName', '$columnName', '{$this->columnToDbType($currentColumnSchema)}');";
            }
        }


        if (empty($upCode) && empty($downCode)) {
            return ['',''];
        }

        return [
            "        " . implode("\n        ", $upCode),
            "        " . implode("\n        ", $downCode),
        ];
    }

    private function columnToDbType(ColumnSchema $column)
    {
        if ($column->dbType === 'pk') {
            return $column->dbType;
        }
        return $column->dbType . ($column->allowNull ? '' : ' NOT NULL');
    }

    private function attributeToColumnSchema($attribute)
    {
        return new ColumnSchema([
            'dbType' => $attribute['dbType'],
            'allowNull' => !$attribute['required'],
            // TODO add more fields
        ]);
    }
}