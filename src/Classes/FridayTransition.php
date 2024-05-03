<?php

namespace LucaTerribili\LaravelWorkflow\Classes;
use Symfony\Component\Workflow\Transition;
class FridayTransition extends Transition
{
    protected bool $handle_by_system = false;
    public function __construct(string $name, string|array $froms, string|array $tos, bool $handle_by_system = false)
    {
        parent::__construct($name, $froms, $tos);
        $this->handle_by_system = $handle_by_system;
    }

    public function isHandleBySystem(): bool
    {
        return $this->handle_by_system;
    }
}
