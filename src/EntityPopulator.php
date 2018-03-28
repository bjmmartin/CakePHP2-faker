<?php

namespace Faker\ORM\CakePHP2;

class EntityPopulator
{
    protected $class;
    protected $connectionName;
    protected $columnFormatters = [];
    protected $modifiers = [];

    public function __construct($class)
    {
        $this->class = $class;
    }

    /**
     * @param string $name
     */
    public function __get($name)
    {
        return $this->{$name};
    }

    /**
     * @param string $name
     */
    public function __set($name, $value)
    {
        $this->{$name} = $value;
    }

    public function mergeColumnFormattersWith($columnFormatters)
    {
        $this->columnFormatters = array_merge($this->columnFormatters, $columnFormatters);
    }

    public function mergeModifiersWith($modifiers)
    {
        $this->modifiers = array_merge($this->modifiers, $modifiers);
    }

    /**
     * @return array
     */
    public function guessColumnFormatters($populator)
    {
        $formatters = [];
        $class = $this->class;
        $table = $this->getTable($class);
        $schema = $table->schema();
        $guessers = $populator->getGuessers() + ['ColumnTypeGuesser' => new ColumnTypeGuesser($populator->getGenerator())];

        foreach ($schema as $column => $value) {
            if ($column == 'id' || $table->isForeignKey($column)) {
                continue;
            }

            foreach ($guessers as $guesser) {
                if ($formatter = $guesser->guessFormat($column, $table, $value)) {
                    $formatters[$column] = $formatter;
                    break;
                }
            }
        }

        return $formatters;
    }

    /**
     * @return array
     */
    public function guessModifiers()
    {
        $modifiers = [];
        $table = $this->getTable($this->class);

        $belongsTo = $table->belongsTo;
        foreach ($belongsTo as $assoc => $params) {
            $modifiers['belongsTo' . $assoc] = function ($data, $insertedEntities) use ($params) {

                $table = \ClassRegistry::init($params['className']);
                $foreignModel = $table->alias;

                $foreignKeys = [];
                if (!empty($insertedEntities[$foreignModel])) {
                    $foreignKeys = $insertedEntities[$foreignModel];
                } else {
                    $foreignKeys = $table->find('list', array('fields' => array('id', 'id'), 'callbacks' => false));
                }

                if (empty($foreignKeys)) {
                    throw new \Exception(sprintf('%s belongsTo %s, which seems empty at this point.', $this->getTable($this->class)->alias, $params['className']));
                }

                $foreignKey = $foreignKeys[array_rand($foreignKeys)];
                $data[$params['foreignKey']] = $foreignKey;
                return $data;
            };
        }
        // TODO check if TreeBehavior attached to modify lft/rgt cols

        return $modifiers;
    }

    /**
     * @param array $options
     */
    public function execute($class, $insertedEntities, $options = [])
    {
        $table = $this->getTable($class);
        $entity = $table->create();

        foreach ($this->columnFormatters as $column => $format) {
            if (!is_null($format)) {
                $entity[$column] = is_callable($format) ? $format() : $format;
            }
        }

        // prd($entity);
        foreach ($this->modifiers as $modifier) {
            $entity = $modifier($entity, $insertedEntities);
        }

        if (!$entity = $table->save($entity)) {
            // prd($table->validationErrors);
            throw new \RuntimeException("Failed saving $class record");
        }

        return $table->id;
    }

    public function setConnection($name)
    {
        $this->connectionName = $name;
    }

    protected function getTable($class)
    {
        /*$options = [];
        if (!empty($this->connectionName)) {
            $options['connection'] = $this->connectionName;
        }*/
        return \ClassRegistry::init($class);
    }
}
