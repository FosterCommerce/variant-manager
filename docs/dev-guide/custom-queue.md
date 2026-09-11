# Custom queue

How to keep Variant Manager's import jobs from delaying other Craft queue work.

Bulk imports can generate thousands of import jobs. By default they go on Craft's main queue, which means other Craft work (search index rebuilds, image transforms, emails) can sit behind a long import batch.

Two ways to address this: lower the priority of Variant Manager jobs, or send them to a custom queue.

## Lower job priority

In a site module, listen for `Queue::EVENT_BEFORE_PUSH` and bump the priority of `ImportJob` instances. Higher priority numbers run later.

```php
use fostercommerce\variantmanager\jobs\Import as ImportJob;
use yii\base\Event;
use yii\queue\PushEvent;
use yii\queue\Queue;

Event::on(
    Queue::class,
    Queue::EVENT_BEFORE_PUSH,
    static function (PushEvent $event): void {
        if ($event->job instanceof ImportJob) {
            // UpdateSearchIndex jobs have a priority of 2048; pushing above that means imports run after search updates.
            $event->priority = 2049;
        }
    }
);
```

Wire this into your module's `init()` method.

## Run imports on a dedicated queue

Configure Variant Manager to push its jobs to a separate Yii queue, so they run on a different worker (or run alongside the main queue without blocking it).

In `config/app.php`:

```php
return [
    'bootstrap' => ['priorityQueue'],
    'components' => [
        'plugins' => [
            'pluginConfigs' => [
                'variant-manager' => [
                    'queue' => 'priorityQueue',
                ],
            ],
        ],
        'priorityQueue' => [
            'class' => \craft\queue\Queue::class,
            'channel' => 'priority',
        ],
    ],
];
```

The string `priorityQueue` is the component handle Variant Manager will resolve at runtime; it can be anything as long as the component is registered.

Then run the worker for the custom queue separately:

```sh
./craft queue/run --queue=priorityQueue
```

See Craft's [custom queues guide](https://craftcms.com/docs/5.x/system/queue.html#custom-queues) for more on how Yii's queue components are wired up.
