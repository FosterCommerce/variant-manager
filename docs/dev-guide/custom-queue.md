# Custom queue

How to keep Variant Manager's import jobs from delaying other Craft queue work.

Bulk imports can generate thousands of import jobs. By default they run on Craft's main queue, so a long import batch delays other Craft work such as search index rebuilds, image transforms, and emails.

Two ways to address this: lower the priority of Variant Manager jobs, or send them to a custom queue.

## Lower job priority

In a site module, listen for `Queue::EVENT_BEFORE_PUSH` and raise the priority of `ImportJob` instances. A job with a higher priority number runs later.

```php
<?php

declare(strict_types=1);

namespace modules;

use fostercommerce\variantmanager\jobs\Import as ImportJob;
use yii\base\Event;
use yii\base\Module as BaseModule;
use yii\queue\PushEvent;
use yii\queue\Queue;

class Module extends BaseModule
{
    public function init(): void
    {
        parent::init();

        Event::on(
            Queue::class,
            Queue::EVENT_BEFORE_PUSH,
            static function (PushEvent $event): void {
                if ($event->job instanceof ImportJob) {
                    // Push above Craft's UpdateSearchIndex priority of 2048, so imports run after search updates.
                    $event->priority = 2049;
                }
            }
        );
    }
}
```

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

The string `priorityQueue` is the component handle Variant Manager resolves at runtime; it can be any name as long as the component is registered.

Then run the worker for the custom queue separately:

```sh
./craft priority-queue/run
```

For how Yii's queue components are registered, see Craft's [custom queues guide](https://craftcms.com/docs/5.x/system/queue.html#custom-queues).
