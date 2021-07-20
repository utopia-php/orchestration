<?php
namespace Utopia\Orchestration;

class Container {
    /**
     * @param string $name
     * @param string $id
     * @param string $status
     * @param array<string, string> $labels
     */
    public function __construct(string $name = '', string $id = '', string $status = '', array $labels = [])
    {
        $this->name = $name;
        $this->id = $id;
        $this->status = $status;
        $this->labels = $labels;
    }

    /**
     * @var string
     */
    public $name = '';

    /**
     * @var string
     */
    public $id = '';

    /**
     * @var string
     */
    public $status = '';

    /**
     * @var array<string, string> 
     */
    public $labels = [];
}