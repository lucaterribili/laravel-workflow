<?php

namespace LucaTerribili\LaravelWorkflow\Classes;

use Symfony\Component\Workflow\Workflow;
class FridayWorkflow extends Workflow
{
    public function can(object $subject, string $transitionName): bool
    {
        $transitions = $this->getDefinition()->getTransitions();
        foreach ($transitions as $transition) {
            if ($transition->getName() === $transitionName) {
                if (auth()->user() !== null && $transition->isHandleBySystem()) {
                    return false;
                }
            }
        }

        return parent::can($subject, $transitionName);
    }
}
