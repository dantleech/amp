<?php /** @noinspection PhpComposerExtensionStubsInspection */

namespace Amp\Loop;

use Amp\Coroutine;
use Amp\Promise;
use React\Promise\PromiseInterface as ReactPromise;
use function Amp\Internal\getCurrentTime;
use function Amp\Promise\rethrow;

class EvDriver extends Driver
{
    /** @var \EvSignal[]|null */
    private static $activeSignals;

    public static function isSupported(): bool
    {
        return \extension_loaded("ev");
    }

    /** @var \EvLoop */
    private $handle;
    /** @var \EvWatcher[] */
    private $events = [];
    /** @var callable */
    private $ioCallback;
    /** @var callable */
    private $timerCallback;
    /** @var callable */
    private $signalCallback;
    /** @var \EvSignal[] */
    private $signals = [];
    /** @var int Internal timestamp for now. */
    private $now;
    /** @var bool */
    private $nowUpdateNeeded = false;
    /** @var int Loop time offset */
    private $nowOffset;

    public function __construct()
    {
        $this->handle = new \EvLoop;
        $this->nowOffset = getCurrentTime();
        $this->now = \random_int(0, $this->nowOffset);
        $this->nowOffset -= $this->now;

        if (self::$activeSignals === null) {
            self::$activeSignals = &$this->signals;
        }

        $this->ioCallback = function (\EvIO $event) {
            /** @var Watcher $watcher */
            $watcher = $event->data;

            try {
                $result = ($watcher->callback)($watcher->id, $watcher->value, $watcher->data);

                if ($result === null) {
                    return;
                }

                if ($result instanceof \Generator) {
                    $result = new Coroutine($result);
                }

                if ($result instanceof Promise || $result instanceof ReactPromise) {
                    rethrow($result);
                }
            } catch (\Throwable $exception) {
                $this->error($exception);
            }
        };

        $this->timerCallback = function (\EvTimer $event) {
            /** @var Watcher $watcher */
            $watcher = $event->data;

            if ($watcher->type & Watcher::DELAY) {
                $this->cancel($watcher->id);
            } elseif ($watcher->value === 0) {
                // Disable and re-enable so it's not executed repeatedly in the same tick
                // See https://github.com/amphp/amp/issues/131
                $this->disable($watcher->id);
                $this->enable($watcher->id);
            }

            try {
                $result = ($watcher->callback)($watcher->id, $watcher->data);

                if ($result === null) {
                    return;
                }

                if ($result instanceof \Generator) {
                    $result = new Coroutine($result);
                }

                if ($result instanceof Promise || $result instanceof ReactPromise) {
                    rethrow($result);
                }
            } catch (\Throwable $exception) {
                $this->error($exception);
            }
        };

        $this->signalCallback = function (\EvSignal $event) {
            /** @var Watcher $watcher */
            $watcher = $event->data;

            try {
                $result = ($watcher->callback)($watcher->id, $watcher->value, $watcher->data);

                if ($result === null) {
                    return;
                }

                if ($result instanceof \Generator) {
                    $result = new Coroutine($result);
                }

                if ($result instanceof Promise || $result instanceof ReactPromise) {
                    rethrow($result);
                }
            } catch (\Throwable $exception) {
                $this->error($exception);
            }
        };
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(string $watcherId)
    {
        parent::cancel($watcherId);
        unset($this->events[$watcherId]);
    }

    public function __destruct()
    {
        foreach ($this->events as $event) {
            /** @psalm-suppress all */
            if ($event !== null) { // Events may have been nulled in extension depending on destruct order.
                $event->stop();
            }
        }

        // We need to clear all references to events manually, see
        // https://bitbucket.org/osmanov/pecl-ev/issues/31/segfault-in-ev_timer_stop
        $this->events = [];
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $active = self::$activeSignals;

        foreach ($active as $event) {
            $event->stop();
        }

        self::$activeSignals = &$this->signals;

        foreach ($this->signals as $event) {
            $event->start();
        }

        try {
            parent::run();
        } finally {
            foreach ($this->signals as $event) {
                $event->stop();
            }

            self::$activeSignals = &$active;

            foreach ($active as $event) {
                $event->start();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->handle->stop();
        parent::stop();
    }

    /**
     * {@inheritdoc}
     */
    public function now(): int
    {
        if ($this->nowUpdateNeeded) {
            $this->now = getCurrentTime() - $this->nowOffset;
            $this->nowUpdateNeeded = false;
        }

        return $this->now;
    }

    /**
     * {@inheritdoc}
     */
    public function getHandle(): \EvLoop
    {
        return $this->handle;
    }

    /**
     * {@inheritdoc}
     */
    protected function dispatch(bool $blocking)
    {
        $this->nowUpdateNeeded = true;
        $this->handle->run($blocking ? \Ev::RUN_ONCE : \Ev::RUN_ONCE | \Ev::RUN_NOWAIT);
    }

    /**
     * {@inheritdoc}
     */
    protected function activate(array $watchers)
    {
        foreach ($watchers as $watcher) {
            if (!isset($this->events[$id = $watcher->id])) {
                switch ($watcher->type) {
                    case Watcher::READABLE:
                        $this->events[$id] = $this->handle->io($watcher->value, \Ev::READ, $this->ioCallback, $watcher);
                        break;

                    case Watcher::WRITABLE:
                        $this->events[$id] = $this->handle->io(
                            $watcher->value,
                            \Ev::WRITE,
                            $this->ioCallback,
                            $watcher
                        );
                        break;

                    case Watcher::DELAY:
                    case Watcher::REPEAT:
                        $interval = $watcher->value / self::MILLISEC_PER_SEC;
                        $this->events[$id] = $this->handle->timer(
                            $interval,
                            ($watcher->type & Watcher::REPEAT) ? $interval : 0,
                            $this->timerCallback,
                            $watcher
                        );
                        break;

                    case Watcher::SIGNAL:
                        $this->events[$id] = $this->handle->signal($watcher->value, $this->signalCallback, $watcher);
                        break;

                    default:
                        // @codeCoverageIgnoreStart
                        throw new \Error("Unknown watcher type");
                    // @codeCoverageIgnoreEnd
                }
            } else {
                $this->events[$id]->start();
            }

            if ($watcher->type === Watcher::SIGNAL) {
                $this->signals[$id] = $this->events[$id];
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function deactivate(Watcher $watcher)
    {
        if (isset($this->events[$id = $watcher->id])) {
            $this->events[$id]->stop();

            if ($watcher->type === Watcher::SIGNAL) {
                unset($this->signals[$id]);
            }
        }
    }
}
