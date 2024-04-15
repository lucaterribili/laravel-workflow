<?php

namespace LucaTerribili\LaravelWorkflow;

use Illuminate\Support\Arr;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\Workflow;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\DefinitionBuilder;
use LucaTerribili\LaravelWorkflow\Events\DispatcherAdapter;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\Exception\InvalidArgumentException;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use LucaTerribili\LaravelWorkflow\MarkingStores\EloquentMarkingStore;
use LucaTerribili\LaravelWorkflow\Exceptions\DuplicateWorkflowException;
use LucaTerribili\LaravelWorkflow\Exceptions\RegistryNotTrackedException;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;

class WorkflowRegistry
{
    /**
     * @var Registry
     */
    protected Registry $registry;

    /**
     * @var array
     */
    protected array $config;

    /**
     * @var
     */
    protected array $db_workflows;

    /**
     * @var
     */
    protected $current_workflow;

    /**
     * @var
     */
    protected $currentClass;

    /**
     * @var array
     */
    protected $registryConfig;

    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * Keeps track of loaded workflows
     * (Useful when loading workflows after the config load)
     *
     * @var array
     */
    protected $loadedWorkflows = [];

    /**
     * WorkflowRegistry constructor
     *
     * @param array $config
     * @param array|null $registryConfig
     * @param EventsDispatcher $laravelDispatcher
     *
     * @throws \ReflectionException
     */
    public function __construct(array $config, array $registryConfig = null, EventsDispatcher $laravelDispatcher)
    {
        $this->registry = new Registry();
        $this->config = $config;
        $this->db_workflows = $this->__loadAllWorkflow();
        $this->registryConfig = $registryConfig ?? $this->getDefaultRegistryConfig();
        $this->dispatcher = new DispatcherAdapter($laravelDispatcher);

        foreach ($this->db_workflows as $name => $workflowData) {
            $this->addFromArray($name, $workflowData);
        }
    }

    /**
     * Return the $subject workflow
     *
     * @param  object $subject
     * @param  string $workflowName
     *
     * @return Workflow
     */
    public function get($subject, $workflowName = null): Workflow
    {
        if (is_null($workflowName)) {
            $workflowName = $this->getWorkflowName($subject);
        }

        return $this->registry->get($subject, $workflowName);
    }

    /**
     * @param mixed $class
     * @param null|mixed $workflow_name
     *
     * @throws \Exception
     */
    public function load($class, $workflow_name = null): static
    {
        $this->currentClass = $class;
        $workflow_scope = collect($this->db_workflows)->filter(callback: function ($workflow, $name) use ($workflow_name) {
            if ($workflow_name) {
                return in_array(get_class($this->currentClass), $workflow['supports']) && $workflow_name === $name;
            } else {
                return in_array(get_class($this->currentClass), $workflow['supports']);
            }
        })->first();

        if (! $workflow_scope) {
            throw new \Exception('Non esiste un Workflow valido per questa richiesta');
        }
        $this->current_workflow = $workflow_scope;

        return $this;
    }

    /**
     * Returns all workflows for the given subject
     *
     * @param object $subject
     *
     * @return Workflow[]
     */
    public function all($subject): array
    {
        return $this->registry->all($subject);
    }

    /**
     * Add a workflow to the subject
     *
     * @param Workflow $workflow
     * @param string $supportStrategy
     *
     * @throws DuplicateWorkflowException
     *
     * @return void
     */
    public function add(Workflow $workflow, $supportStrategy)
    {
        if (! $this->isLoaded($workflow->getName(), $supportStrategy)) {
            $this->registry->addWorkflow($workflow, new InstanceOfSupportStrategy($supportStrategy));
            $this->setLoaded($workflow->getName(), $supportStrategy);
        }
    }

    /**
     * Gets the loaded workflows
     *
     * @param string $supportStrategy
     *
     * @throws RegistryNotTrackedException
     *
     * @return array
     */
    public function getLoaded($supportStrategy = null)
    {
        if (! $this->registryConfig['track_loaded']) {
            throw new RegistryNotTrackedException('This registry is not being tracked, and thus has not recorded any loaded workflows.');
        }

        if ($supportStrategy) {
            return $this->loadedWorkflows[$supportStrategy] ?? [];
        }

        return $this->loadedWorkflows;
    }

    /**
     * Add a workflow to the registry from array
     *
     * @param  string $name
     * @param  array  $workflowData
     *
     * @throws \ReflectionException
     *
     * @return void
     */
    public function addFromArray($name, array $workflowData)
    {
        $metadata = $this->extractWorkflowPlacesMetaData($workflowData);

        $builder = new DefinitionBuilder($workflowData['places']);

        foreach ($workflowData['transitions'] as $transitionName => $transition) {
            if (! is_string($transitionName)) {
                $transitionName = $transition['name'];
            }

            foreach ((array) $transition['from'] as $from) {
                $transitionObj = new Transition($transitionName, $from, $transition['to']);
                $builder->addTransition($transitionObj);

                if (isset($transition['metadata'])) {
                    $metadata['transitions']->attach($transitionObj, $transition['metadata']);
                }
            }
        }

        $metadataStore = new InMemoryMetadataStore(
            $metadata['workflow'],
            $metadata['places'],
            $metadata['transitions']
        );

        $builder->setMetadataStore($metadataStore);

        if (isset($workflowData['initial_places'])) {
            $builder->setInitialPlaces($workflowData['initial_places']);
        }

        $eventsToDispatch = $this->parseEventsToDispatch($workflowData);

        $definition = $builder->build();
        $markingStore = $this->getMarkingStoreInstance($workflowData);
        $workflow = $this->getWorkflowInstance($name, $workflowData, $definition, $markingStore, $eventsToDispatch);

        foreach ($workflowData['supports'] as $supportedClass) {
            $this->add($workflow, $supportedClass);
        }
    }

    /**
     * @param $obejct
     *
     * @return false|int|string
     */
    public function getLabelStatus($obejct): bool|int|string
    {
        $property = $this->current_workflow['marking_store']['property'];

        return array_search($obejct->$property, $this->current_workflow['places']) ?: '';
    }

    /**
     * @return mixed
     */
    public function getLastStatus(): mixed
    {
        return $this->current_workflow['final_status'];
    }

    /**
     * @return mixed
     */
    public function getFinalStatus(): mixed
    {
        return $this->current_workflow['final_status'];
    }

    public function hasTransition(): bool
    {
        if(count($this->current_workflow['transitions']) > 0) {
            $transitions = array_keys($this->current_workflow['transitions']);

            return count(Arr::where($transitions, fn ($transition) => $this->get($this->currentClass)->can($this->currentClass, $transition))) > 0;
        }

        return false;
    }

    public function getTransitions(): array
    {
        return $this->current_workflow['transitions'];
    }

    /**
     * @param $places
     * @param mixed $workflow
     *
     * @return mixed[]
     */
    protected function createPlace($workflow)
    {
        $workflow_places = $workflow->getAllStatusAttribute(false);
        $start_label = array_search($workflow->start_place, $workflow_places);
        $places = [$start_label => $workflow->start_place];

        foreach ($workflow_places as $label => $place) {
            if (! in_array($place, $places)) {
                $places[$label] = $place;
            }
        }

        return $places;
    }

    /**
     * @param $subejct
     *
     * @return int|string|null
     */
    protected function getWorkflowName($subejct)
    {
        $class_name = $subejct::class;
        $workflow_name = Arr::where($this->db_workflows, fn ($arr) => in_array($class_name, $arr['supports']));

        return key($workflow_name);
    }

    /**
     * Parses events to dispatch data from config
     */
    protected function parseEventsToDispatch(array $workflowData)
    {
        if (array_key_exists('events_to_dispatch', $workflowData)) {
            return $workflowData['events_to_dispatch'];
        }

        // Null dispatches all, [] dispatches none.
        return null;
    }

    /**
     * Gets the default registry config
     *
     * @return array
     */
    protected function getDefaultRegistryConfig()
    {
        return [
            'track_loaded' => false,
            'ignore_duplicates' => true,
        ];
    }

    /**
     * Checks if the workflow is already loaded for this supported class
     *
     * @param string $workflowName
     * @param string $supportStrategy
     *
     * @throws DuplicateWorkflowException
     *
     * @return bool
     */
    protected function isLoaded($workflowName, $supportStrategy)
    {
        if (! $this->registryConfig['track_loaded']) {
            return false;
        }

        if (isset($this->loadedWorkflows[$supportStrategy]) && in_array($workflowName, $this->loadedWorkflows[$supportStrategy])) {
            if (! $this->registryConfig['ignore_duplicates']) {
                throw new DuplicateWorkflowException(sprintf('Duplicate workflow (%s) attempting to be loaded for %s', $workflowName, $supportStrategy)); // phpcs:ignore
            }

            return true;
        }

        return false;
    }

    /**
     * Sets the workflow as loaded
     *
     * @param string $workflowName
     * @param string $supportStrategy
     *
     * @return void
     */
    protected function setLoaded($workflowName, $supportStrategy)
    {
        if (! $this->registryConfig['track_loaded']) {
            return;
        }

        if (! isset($this->loadedWorkflows[$supportStrategy])) {
            $this->loadedWorkflows[$supportStrategy] = [];
        }

        $this->loadedWorkflows[$supportStrategy][] = $workflowName;
    }

    /**
     * Return the workflow instance
     *
     * @param  string                $name
     * @param  array                 $workflowData
     * @param  Definition            $definition
     * @param  MarkingStoreInterface $markingStore
     *
     * @return Workflow
     */
    protected function getWorkflowInstance(
        $name,
        array $workflowData,
        Definition $definition,
        MarkingStoreInterface $markingStore,
        ?array $eventsToDispatch = null
    ) {
        if (isset($workflowData['class'])) {
            $className = $workflowData['class'];

            return new $className($definition, $markingStore, $this->dispatcher, $name);
        } elseif (isset($workflowData['type']) && $workflowData['type'] === 'state_machine') {
            return new StateMachine($definition, $markingStore, $this->dispatcher, $name);
        } else {
            return new Workflow($definition, $markingStore, $this->dispatcher, $name, $eventsToDispatch);
        }
    }

    /**
     * Return the making store instance
     *
     * @param  array $workflowData
     *
     * @throws \ReflectionException
     *
     * @return MarkingStoreInterface
     */
    protected function getMarkingStoreInstance(array $workflowData)
    {
        $markingStoreData = $workflowData['marking_store'] ?? [];
        $property = $markingStoreData['property'] ?? 'marking';

        if (array_key_exists('type', $markingStoreData)) {
            $type = $markingStoreData['type'];
        } else {
            $workflowType = $workflowData['type'] ?? 'workflow';
            $type = ($workflowType === 'state_machine') ? 'single_state' : 'multiple_state';
        }

        $markingStoreClass = $markingStoreData['class'] ?? EloquentMarkingStore::class;

        return new $markingStoreClass(
            ($type === 'single_state'),
            $property
        );
    }

    /**
     * Extracts workflow and places metadata from the config
     * NOTE: This modifies the provided config!
     *
     * @param array $workflowData
     *
     * @return array
     */
    protected function extractWorkflowPlacesMetaData(array &$workflowData)
    {
        $metadata = [
            'workflow' => [],
            'places' => [],
            'transitions' => new \SplObjectStorage(),
        ];

        if (isset($workflowData['metadata'])) {
            $metadata['workflow'] = $workflowData['metadata'];
            unset($workflowData['metadata']);
        }

        foreach ($workflowData['places'] as $key => &$place) {
            if (is_int($key) && ! is_array($place)) {
                // no metadata, just place name
                continue;
            }

            if (isset($place['metadata'])) {
                if (is_int($key) && ! $place['name']) {
                    throw new InvalidArgumentException(sprintf('Unknown name for place at index %d', $key));
                }

                $name = ! is_int($key) ? $key : $place['name'];
                $metadata['places'][$name] = $place['metadata'];

                $place = $name;
            }
        }

        return $metadata;
    }

    /**
     * @param $transition_name
     *
     * @return bool
     */
    protected function canHelper($transition_name)
    {
        if (is_object($this->currentClass) && is_array($this->current_workflow)) {
            return $this->registry->get($this->currentClass, $this->current_workflow['name'])->can($this->currentClass, $transition_name);
        }

        return false;
    }

    /**
     * @return array
     */
    private function __loadAllWorkflow()
    {
        $workflows = $this->config['models']['workflow']::with('transitions')->get();
        $array_workflow = [];

        foreach ($workflows as $workflow) {
            $array_workflow[$workflow->name] = [
                'type' => 'workflow',
                'marking_store' => [
                    'type' => 'single_state',
                    'property' => 'status',
                ],
                'name' => $workflow->name,
                'supports' => $workflow->supports,
                'places' => $this->createPlace($workflow),
                'final_status' => $workflow->final_place,
                'last_places' => $workflow->last_places,
                'transitions' => [],
                'events_to_dispatch' => $this->config['events_to_dispatch'] ?? [],
            ];

            if (array_key_exists('extra_fields', $this->config) && is_array($this->config['extra_fields'])) {
                foreach ($this->config['extra_fields'] as $extra_field) {
                    $array_workflow[$workflow->name][$extra_field] = $workflow->{$extra_field};
                }
            }

            foreach ($workflow->transitions as $transition) {
                $array_workflow[$workflow->name]['transitions'][$transition->name] = [
                    'title' => $transition->label,
                    'from' => $transition->from,
                    'to' => $transition->to,
                    'permission' => $transition->permission,
                ];
            }
        }

        return $array_workflow;
    }
}
