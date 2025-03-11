# Queues

When importing products from a zip file, Variant Manager could generate thousands of import jobs, potentially delaying other important jobs.

There are a couple of options to get around this - Lower the priority of the queue jobs created by Variant Manager, or configure Variant Manager to use a [custom queue](https://craftcms.com/docs/5.x/system/queue.html#custom-queues).

## Import Job Priority 

If you have a module configured for your site, you can set the job priority for Variant Manager import jobs.

Add an event handler for the `Queue::EVENT_BEFORE_PUSH` event and set the priority to something that will allow most other Craft jobs to be processed first:

```php
use fostercommerce\variantmanager\jobs\Import as ImportJob;
use yii\base\Event;
use yii\queue\PushEvent;
use yii\queue\Queue;

// ...

Event::on(Queue::class, Queue::EVENT_BEFORE_PUSH,
    static function (PushEvent $event) {
        if ($event->job instanceof ImportJob) {
            $event->priority = 2049; // UpdateSearchIndex has a priority of 2048
        }
    }
);
```

Note that higher numbers are lower priority.

## Custom Queue

To enable jobs to be processed on a custom queue, update your app.php config to include configuration for a new queue and to set it as the queue where Variant Manager jobs should be pushed to:

```php
return [
	// ... Your other config
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
