<?php

namespace Tests;

use Tests\Fixtures\TestObject;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Workflow\WorkflowEvents;
use LucaTerribili\LaravelWorkflow\WorkflowRegistry;
use LucaTerribili\LaravelWorkflow\Events\EnterEvent;
use LucaTerribili\LaravelWorkflow\Events\GuardEvent;
use LucaTerribili\LaravelWorkflow\Events\LeaveEvent;
use LucaTerribili\LaravelWorkflow\Events\EnteredEvent;
use LucaTerribili\LaravelWorkflow\Events\AnnounceEvent;
use LucaTerribili\LaravelWorkflow\Events\CompletedEvent;
use LucaTerribili\LaravelWorkflow\Events\TransitionEvent;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;

class WorkflowEventsTest extends BaseWorkflowTestCase
{
    private $eventSets = [
        'workflow_enter' => [
            EnteredEvent::class,
            'workflow.entered',
            'workflow.%s.entered',
        ],
        'guard' => [
            GuardEvent::class,
            'workflow.guard',
            'workflow.%s.guard',
            'workflow.%s.guard.%s',
        ],
        'leave' => [
            LeaveEvent::class,
            'workflow.leave',
            'workflow.%s.leave',
            'workflow.%s.leave.%s',
        ],
        'transition' => [
            TransitionEvent::class,
            'workflow.transition',
            'workflow.%s.transition',
            'workflow.%s.transition.%s',
        ],
        'enter' => [
            EnterEvent::class,
            'workflow.enter',
            'workflow.%s.enter',
            'workflow.%s.enter.%s',
        ],
        'entered' => [
            EnteredEvent::class,
            'workflow.entered',
            'workflow.%s.entered',
            'workflow.%s.entered.%s',
        ],
        'completed' => [
            CompletedEvent::class,
            'workflow.completed',
            'workflow.%s.completed',
            'workflow.%s.completed.%s',
        ],
        'announce' => [
            AnnounceEvent::class,
            'workflow.announce',
            'workflow.%s.announce',
        ],
    ];

    /**
     * @test
     */
    public function testIfWorkflowEmitsEvents()
    {
        Event::fake();

        $config = [
            'straight' => [
                'supports' => [TestObject::class],
                'places' => ['a', 'b', 'c'],
                'transitions' => [
                    't1' => [
                        'from' => 'a',
                        'to' => 'b',
                    ],
                    't2' => [
                        'from' => 'b',
                        'to' => 'c',
                    ],
                ],
            ],
        ];

        $registry = new WorkflowRegistry($config, null, $this->app->make(EventsDispatcher::class));
        $object = new TestObject();
        $workflow = $registry->get($object);

        $workflow->apply($object, 't1');

        // Symfony Workflow 4.2.9 fires entered event on initialize
        $this->assertEventSetDispatched('workflow_enter');

        $this->assertEventSetDispatched('guard', 't1');

        $this->assertEventSetDispatched('leave', 'a');

        $this->assertEventSetDispatched('transition', 't1');

        $this->assertEventSetDispatched('enter', 'b');

        $this->assertEventSetDispatched('entered', 'b');

        $this->assertEventSetDispatched('completed', 't1');

        // Announce happens after completed
        $this->assertEventSetDispatched('announce', 't1');
        Event::assertDispatched('workflow.straight.announce.t2');

        $this->assertEventSetDispatched('guard', 't2');
    }

    /**
     * @test
     *
     * @dataProvider providesEventsToDispatchScenarios
     */
    public function testIfWorkflowOnlyEmitsSpecificEvents(?array $eventsToDispatch, array $eventsToExpect)
    {
        Event::fake();

        $config = [
            'straight' => [
                'supports' => [TestObject::class],
                'places' => ['a', 'b', 'c'],
                'events_to_dispatch' => $eventsToDispatch,
                'transitions' => [
                    't1' => [
                        'from' => 'a',
                        'to' => 'b',
                    ],
                    't2' => [
                        'from' => 'b',
                        'to' => 'c',
                    ],
                ],
            ],
        ];

        $registry = new WorkflowRegistry($config, null, $this->app->make(EventsDispatcher::class));
        $object = new TestObject();
        $workflow = $registry->get($object);

        $workflow->apply($object, 't1');

        // Ignoring guard since it's always dispatched
        $this->assertEventSetDispatched('workflow_enter', null, in_array('entered', $eventsToExpect));
        $this->assertEventSetDispatched('leave', 'a', in_array('leave', $eventsToExpect));
        $this->assertEventSetDispatched('transition', 't1', in_array('transition', $eventsToExpect));
        $this->assertEventSetDispatched('enter', 'b', in_array('enter', $eventsToExpect));
        $this->assertEventSetDispatched('entered', 'b', in_array('entered', $eventsToExpect));
        $this->assertEventSetDispatched('completed', 't1', in_array('completed', $eventsToExpect));
        $this->assertEventSetDispatched('announce', 't1', in_array('announce', $eventsToExpect));
    }

    public function providesEventsToDispatchScenarios()
    {
        $events = [
            'enter' => WorkflowEvents::ENTER,
            'leave' => WorkflowEvents::LEAVE,
            'transition' => WorkflowEvents::TRANSITION,
            'entered' => WorkflowEvents::ENTERED,
            'completed' => WorkflowEvents::COMPLETED,
            'announce' => WorkflowEvents::ANNOUNCE,
        ];

        yield 'null events dispatches all' => [
            null, array_keys($events),
        ];

        yield 'empty events dispatches none' => [
            [], [],
        ];

        foreach ($events as $key => $event) {
            yield "silences ${event}" => [[$event], [$key]];
        }
    }

    /**
     * @test
     */
    public function testIfWorkflowEmitsEventsWithContext()
    {
        Event::fake();

        $config = [
            'straight' => [
                'supports' => [TestObject::class],
                'places' => ['a', 'b', 'c'],
                'transitions' => [
                    't1' => [
                        'from' => 'a',
                        'to' => 'b',
                    ],
                    't2' => [
                        'from' => 'b',
                        'to' => 'c',
                    ],
                ],
            ],
        ];

        $registry = new WorkflowRegistry($config, null, $this->app->make(EventsDispatcher::class));
        $object = new TestObject();
        $workflow = $registry->get($object);

        $context = ['context1' => 42, 'context2' => 'banana'];

        $workflow->apply($object, 't1', $context);

        // Symfony Workflow 4.2.9 fires entered event on initialize
        Event::assertDispatched(function (EnteredEvent $event) use ($context) {
            return $event->getContext() == $context;
        });
        Event::assertDispatched(function (LeaveEvent $event) use ($context) {
            return $event->getContext() == $context;
        });
        Event::assertDispatched(function (TransitionEvent $event) use ($context) {
            return $event->getContext() == $context;
        });
        Event::assertDispatched(function (EnterEvent $event) use ($context) {
            return $event->getContext() == $context;
        });
        Event::assertDispatched(function (CompletedEvent $event) use ($context) {
            return $event->getContext() == $context;
        });
        Event::assertDispatched(function (AnnounceEvent $event) use ($context) {
            return $event->getContext() == $context;
        });
    }

    /**
     * @test
     */
    public function testWorkflowGuardEventsBlockTransition()
    {
        $config = [
            'straight' => [
                'supports' => [TestObject::class],
                'places' => ['a', 'b', 'c'],
                'transitions' => [
                    't1' => [
                        'from' => 'a',
                        'to' => 'b',
                    ],
                    't2' => [
                        'from' => 'b',
                        'to' => 'c',
                    ],
                ],
            ],
        ];

        $registry = new WorkflowRegistry($config, null, $this->app->make(EventsDispatcher::class));
        $object = new TestObject();
        $workflow = $registry->get($object);

        Event::listen('workflow.straight.guard.t1', function ($event) {
            $event->setBlocked(true);
        });

        $this->assertFalse($workflow->can($object, 't1'));

        $this->expectException(NotEnabledTransitionException::class);
        $workflow->apply($object, 't1');
    }

    private function assertEventSetDispatched(string $eventSet, ?string $arg = null, bool $expected = true)
    {
        $workflow = 'straight';

        $method = ($expected) ? 'assertDispatched' : 'assertNotDispatched';

        foreach ($this->eventSets[$eventSet] as $event) {
            Event::$method(sprintf($event, $workflow, $arg));
        }
    }
}
