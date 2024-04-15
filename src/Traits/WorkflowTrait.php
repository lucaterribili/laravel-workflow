<?php

namespace LucaTerribili\LaravelWorkflow\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;
use LucaTerribili\LaravelWorkflow\Events\WorkflowStart;
use LucaTerribili\LaravelWorkflow\Facades\WorkflowFacade;

trait WorkflowTrait
{
    public function workflow_get($workflow = null)
    {
        return WorkflowFacade::get($this, $workflow);
    }

    public function workflow_apply($transition, $workflow = null, array $context = [])
    {
        $workflow = WorkflowFacade::get($this, $workflow);

        if (is_array($workflow)) {
            $context = $workflow;
            $workflow = null;
        }

        return $workflow->apply($this, $transition, $context);
    }

    /**
     * @param $transition
     * @param $workflow
     *
     * @return mixed
     */
    public function workflow_can($transition, $workflow = null)
    {
        return WorkflowFacade::get($this, $workflow)->can($this, $transition);
    }

    /**
     * @param $workflow
     *
     * @return mixed
     */
    public function workflow_transitions($workflow = null)
    {
        return WorkflowFacade::get($this, $workflow)->getEnabledTransitions($this);
    }

    /**
     * @param $workflow
     *
     * @return mixed
     */
    public function getStartStatus($workflow = null)
    {
        return WorkflowFacade::get($this, $workflow)->getDefinition()->getInitialPlaces()[0] ?? '';
    }

    /**
     * @param $workflow
     *
     * @return mixed
     */
    public function getAllFinalStatus($workflow = null)
    {
        return WorkflowFacade::load($this, $workflow)->getLastStatus();
    }

    /**
     * @param $workflow
     *
     * @return mixed
     */
    public function getFinalStatus($workflow = null)
    {
        return WorkflowFacade::load($this, $workflow)->getFinalStatus();
    }

    /**
     * @param $post_action
     *
     * @return $this
     */
    public function saveStatusToStart($post_action = false)
    {
        $status = $this->getStartStatus();

        if (! empty($status)) {
            $this->status = $status;
            $this->save();

            if ($post_action) {
                event(new WorkflowStart($this));
            }
        }

        return $this;
    }

    public function saveStatus(): static
    {
        if (request()->has('transition') && request()->filled('transition')) {
            if ($this->workflow_can(request()->post('transition'))) {
                $this->workflow_apply(request()->post('transition'));
            } else {
                redirect()->back()->withErrors(['Transitions', 'Non è possibile effettuare questa transizione'])->send();
            }
        }

        if (request()->has('published_at') && request()->filled('published_at')) {
            $this->published_at = request()->post('published_at');
        }
        $this->save();

        return $this;
    }

    /**
     * @param $transition
     *
     * @return $this
     */
    public function apply($transition)
    {
        if ($this->workflow_can($transition)) {
            $this->workflow_apply($transition);
            $this->save();
        }

        return $this;
    }

    public function hasTransition()
    {
        return WorkflowFacade::hasTransition();
    }

    public function getTransitions()
    {
        $result = [];

        foreach (WorkflowFacade::getTransitions() as $transition_name => $elements) {
            $result[] = [
                'name' => $transition_name,
                'title' => $elements['title'],
            ];
        }

        return $result;
    }

    /**
     * @return Attribute
     */
    protected function currentStatusLabel(): Attribute
    {
        return new Attribute(
            get: fn () => WorkflowFacade::load($this)->getLabelStatus($this)
        );
    }
}
